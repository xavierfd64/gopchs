#!/usr/bin/env python3
"""Minimal SMTP test server for tests/run_v13.php.

Supports EHLO, STARTTLS, AUTH LOGIN, MAIL, RCPT, DATA, QUIT. Each accepted message is written
to <outdir>/<n>.eml; the credentials received are written next to it (<n>.auth) so the test can
check that authentication happened (the sink is local and throwaway).

Usage: smtp_sink.py <port> <outdir> <certfile> <keyfile>
"""
import base64
import os
import socket
import ssl
import sys
import threading

port, outdir, certfile, keyfile = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]
os.makedirs(outdir, exist_ok=True)
counter = [0]
lock = threading.Lock()


def handle(conn):
    f = conn.makefile('rwb')
    tls = False

    def send(line):
        f.write((line + '\r\n').encode())
        f.flush()

    def read():
        line = f.readline()
        return line.decode('utf-8', 'replace').rstrip('\r\n') if line else None

    send('220 sink ESMTP')
    auth, mail_from, rcpts = '', '', []
    while True:
        line = read()
        if line is None:
            return
        cmd = line.upper()
        if cmd.startswith('EHLO') or cmd.startswith('HELO'):
            f.write(b'250-sink\r\n')
            if not tls:
                f.write(b'250-STARTTLS\r\n')
            send('250 AUTH LOGIN')
        elif cmd == 'STARTTLS':
            send('220 go ahead')
            ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            ctx.load_cert_chain(certfile, keyfile)
            conn = ctx.wrap_socket(conn, server_side=True)
            f = conn.makefile('rwb')
            tls = True
        elif cmd == 'AUTH LOGIN':
            send('334 VXNlcm5hbWU6')
            user = base64.b64decode(read() or '').decode()
            send('334 UGFzc3dvcmQ6')
            pw = base64.b64decode(read() or '').decode()
            if pw == 'wrong-password':
                send('535 authentication failed')
                continue
            auth = f'{user}:{pw}:tls={tls}'
            send('235 ok')
        elif cmd.startswith('MAIL FROM:'):
            mail_from, rcpts = line[10:], []
            send('250 ok')
        elif cmd.startswith('RCPT TO:'):
            rcpts.append(line[8:])
            send('250 ok')
        elif cmd == 'DATA':
            send('354 end with .')
            data = []
            while True:
                l = read()
                if l is None or l == '.':
                    break
                data.append(l[1:] if l.startswith('..') else l)
            with lock:
                counter[0] += 1
                n = counter[0]
            with open(os.path.join(outdir, f'{n}.eml'), 'w') as out:
                out.write('X-Envelope-From: ' + mail_from + '\r\nX-Envelope-To: ' + ','.join(rcpts) + '\r\n' + '\r\n'.join(data))
            with open(os.path.join(outdir, f'{n}.auth'), 'w') as out:
                out.write(auth)
            send('250 queued')
        elif cmd == 'QUIT':
            send('221 bye')
            return
        else:
            send('250 ok')


srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(('127.0.0.1', port))
srv.listen(8)
print('ready', flush=True)
while True:
    c, _ = srv.accept()
    threading.Thread(target=lambda c=c: (handle(c), c.close()), daemon=True).start()
