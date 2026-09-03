"""Exercise the real WinSCP adapter against a disposable loopback-only FTPS server."""
import argparse
from datetime import datetime, timedelta, timezone
import json
import logging
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import threading

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.filesystems import AbstractedFS
from pyftpdlib.handlers import TLS_FTPHandler
from pyftpdlib.servers import FTPServer


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--winscp", required=True)
    args = parser.parse_args()
    logging.basicConfig(level=logging.ERROR)
    root = Path(tempfile.mkdtemp(prefix="portfolio-ftps-integration-"))
    server_root = root / "server"
    app = server_root / "site/apps/portafoglio"
    for name, content in {
        "index.php": "OLD INDEX",
        "VERSION": "2.7.4",
        "storage/report.json": "KEEP REPORT",
        "config/config.local.php": "KEEP PRIVATE CONFIG",
    }.items():
        path = app / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "localhost")])
    now = datetime.now(timezone.utc)
    cert = (
        x509.CertificateBuilder().subject_name(name).issuer_name(name)
        .public_key(key.public_key()).serial_number(x509.random_serial_number())
        .not_valid_before(now - timedelta(minutes=1)).not_valid_after(now + timedelta(hours=1))
        .sign(key, hashes.SHA256())
    )
    pem = root / "localhost.pem"
    pem.write_bytes(cert.public_bytes(serialization.Encoding.PEM) + key.private_bytes(
        serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()
    ))
    password = secrets.token_urlsafe(24)
    authorizer = DummyAuthorizer()
    authorizer.add_user("fixture", password, str(server_root), perm="elradfmwMT")

    class LinuxRenameFilesystem(AbstractedFS):
        def rename(self, src, dst):
            # Match the hosting's POSIX rename semantics while running this fixture on Windows.
            os.replace(src, dst)

    class Handler(TLS_FTPHandler):
        # A test certificate pinned by SHA-256, never installed in the Windows trust store.
        certfile = str(pem)
        tls_control_required = True
        tls_data_required = True
        abstracted_fs = LinuxRenameFilesystem

    Handler.authorizer = authorizer
    server = FTPServer(("127.0.0.1", 0), Handler)
    port = server.socket.getsockname()[1]
    fixture = root / "fixture.json"
    fixture.write_text(json.dumps({
        "Port": port, "Password": password, "Root": str(root),
        "Fingerprint": cert.fingerprint(hashes.SHA256()).hex(":").upper(),
    }), encoding="utf-8")
    thread = threading.Thread(target=server.serve_forever, kwargs={"timeout": 0.1}, daemon=True)
    thread.start()
    try:
        powershell = Path(os.environ["SystemRoot"]) / "System32/WindowsPowerShell/v1.0/powershell.exe"
        env = {key: value for key, value in os.environ.items() if key.lower() != "psmodulepath"}
        result = subprocess.run([
            str(powershell), "-NoProfile", "-ExecutionPolicy", "RemoteSigned", "-File",
            str(Path(__file__).with_suffix(".ps1")), "-Fixture", str(fixture), "-ToolsPath", args.winscp,
        ], timeout=120, creationflags=subprocess.CREATE_NO_WINDOW, capture_output=True, env=env)
        print(result.stdout.decode("utf-8", errors="replace"))
        if result.stderr:
            print(result.stderr.decode("utf-8", errors="replace"))
        if result.returncode:
            raise SystemExit(result.returncode)
    finally:
        server.close_all()
        thread.join(timeout=3)


if __name__ == "__main__":
    main()
