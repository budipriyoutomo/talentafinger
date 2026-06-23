# -*- coding: utf-8 -*-
"""
Jembatan sync sidik jari ZKTeco via TCP 4370 (dipakai Laravel ZkSyncService).

Protokol: baca 1 objek JSON dari STDIN, tulis 1 objek JSON ke STDOUT.

Action:
  pull   {ip, port, pin}                         -> {ok, user, templates[]}
  push   {ip, port, pin, name, privilege, templates[]} -> {ok, uid, installed, fids[]}
  delete {ip, port, pin}                          -> {ok, deleted}
  list   {ip, port}                               -> {ok, device_users, users[]}
  info   {ip, port}                               -> {ok, firmware, ...}

template item: {fid:int, valid:int, tmp:"<base64 bytes>"}
Semua hasil selalu JSON; error dikembalikan sebagai {ok:false, error:"..."} (exit 0).
"""
import sys
import json
import base64

try:
    from zk import ZK
    from zk.finger import Finger
except Exception as e:  # pyzk belum terpasang
    print(json.dumps({"ok": False, "error": "pyzk tidak tersedia: %r" % e}))
    sys.exit(0)


def get_conn(req):
    return ZK(
        req["ip"],
        port=int(req.get("port", 4370)),
        timeout=int(req.get("timeout", 10)),
        ommit_ping=True,
    ).connect()


def _find_user(users, pin):
    pin = str(pin)
    for u in users:
        if str(u.user_id) == pin:
            return u
    return None


def do_pull(req):
    conn = get_conn(req)
    try:
        users = conn.get_users()
        u = _find_user(users, req["pin"])
        if not u:
            return {"ok": False, "error": "PIN %s tidak ada di mesin sumber" % req["pin"]}
        fingers = [t for t in conn.get_templates() if t.uid == u.uid and t.valid]
        if not fingers:
            return {"ok": False, "error": "PIN %s tidak punya template valid" % req["pin"]}
        return {
            "ok": True,
            "user": {"pin": str(u.user_id), "name": u.name, "privilege": u.privilege},
            "templates": [
                {"fid": f.fid, "valid": f.valid, "tmp": base64.b64encode(f.template).decode()}
                for f in fingers
            ],
        }
    finally:
        conn.disconnect()


def do_push(req):
    conn = get_conn(req)
    try:
        existing = conn.get_users()
        u = _find_user(existing, req["pin"])
        uid = u.uid if u else ((max([x.uid for x in existing]) + 1) if existing else 1)
        pin = str(req["pin"])

        conn.disable_device()
        try:
            conn.set_user(
                uid=uid,
                name=(req.get("name") or pin)[:24],
                privilege=int(req.get("privilege", 0)),
                password="",
                group_id="",
                user_id=pin,
            )
            saved = _find_user(conn.get_users(), pin)
            fingers = [
                Finger(uid=uid, fid=int(t["fid"]), valid=int(t.get("valid", 1)),
                       template=base64.b64decode(t["tmp"]))
                for t in req["templates"]
            ]
            conn.save_user_template(saved, fingers)
        finally:
            conn.enable_device()

        got = [t for t in conn.get_templates() if t.uid == uid and t.valid]
        return {"ok": True, "pin": pin, "uid": uid,
                "installed": len(got), "fids": sorted(g.fid for g in got)}
    finally:
        conn.disconnect()


def do_delete(req):
    conn = get_conn(req)
    try:
        conn.delete_user(user_id=str(req["pin"]))
        still = _find_user(conn.get_users(), req["pin"])
        return {"ok": True, "deleted": still is None}
    finally:
        conn.disconnect()


def do_list(req):
    conn = get_conn(req)
    try:
        users = conn.get_users()
        cnt = {}
        for t in conn.get_templates():
            if t.valid:
                cnt[t.uid] = cnt.get(t.uid, 0) + 1
        return {
            "ok": True,
            "device_users": len(users),
            "users": [
                {"pin": str(u.user_id), "name": u.name, "fingers": cnt.get(u.uid, 0)}
                for u in users
            ],
        }
    finally:
        conn.disconnect()


def do_info(req):
    conn = get_conn(req)
    try:
        # read_sizes() mengisi atribut kapasitas: users/fingers/records (terpakai)
        # dan *_cap (maksimum). Tanpa ini atributnya belum tersedia.
        conn.read_sizes()

        # Hitung jumlah admin (privilege != 0 = admin/manager/enroller).
        # get_users() bisa lambat/gagal di sebagian mesin -> jangan gagalkan info.
        admin_count = None
        try:
            admin_count = sum(1 for u in conn.get_users() if int(u.privilege or 0) != 0)
        except Exception:
            admin_count = None

        return {
            "ok": True,
            "firmware": conn.get_firmware_version(),
            "device_name": conn.get_device_name(),
            # Kapasitas (terpakai / maksimum). Nilai bisa None bila firmware tak melaporkan.
            "users": getattr(conn, "users", None),
            "users_cap": getattr(conn, "users_cap", None),
            "admin_count": admin_count,
            "fingers": getattr(conn, "fingers", None),
            "fingers_cap": getattr(conn, "fingers_cap", None),
            "records": getattr(conn, "records", None),
            "records_cap": getattr(conn, "rec_cap", None),
        }
    finally:
        conn.disconnect()


def do_clear_attendance(req):
    """Hapus SEMUA log presensi (records) di mesin. Protokol ZK tak punya
    perintah hapus parsial -> selalu clear total. Kembalikan jumlah sebelum/sesudah."""
    conn = get_conn(req)
    try:
        conn.read_sizes()
        before = getattr(conn, "records", None)
        conn.disable_device()
        try:
            conn.clear_attendance()
        finally:
            conn.enable_device()
        conn.read_sizes()
        after = getattr(conn, "records", None)
        return {"ok": True, "records_before": before, "records_after": after}
    finally:
        conn.disconnect()


ACTIONS = {"pull": do_pull, "push": do_push, "delete": do_delete, "list": do_list,
           "info": do_info, "clear_attendance": do_clear_attendance}


def main():
    raw = sys.stdin.read()
    try:
        req = json.loads(raw)
    except Exception as e:
        print(json.dumps({"ok": False, "error": "JSON input invalid: %r" % e}))
        return
    fn = ACTIONS.get(req.get("action"))
    if not fn:
        print(json.dumps({"ok": False, "error": "action tidak dikenal: %r" % req.get("action")}))
        return
    try:
        print(json.dumps(fn(req)))
    except Exception as e:
        print(json.dumps({"ok": False, "error": "%r" % e}))


if __name__ == "__main__":
    main()
