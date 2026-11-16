"""Compile the plugin's .po files in infopeak-captcha/languages/ to .mo files.

    python tools/build-mo.py

Only simple msgid/msgstr pairs (no plurals or contexts), which is all the plugin ships.
"""
import ast
import pathlib
import struct

LANG_DIR = pathlib.Path(__file__).resolve().parent.parent / "infopeak-captcha" / "languages"


def parse_po(path):
    entries, key, current = {}, None, {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("msgid "):
            if "msgid" in current and "msgstr" in current:
                entries[current["msgid"]] = current["msgstr"]
            current, key = {"msgid": ast.literal_eval(line[6:])}, "msgid"
        elif line.startswith("msgstr "):
            current["msgstr"], key = ast.literal_eval(line[7:]), "msgstr"
        elif line.startswith('"') and key:
            current[key] += ast.literal_eval(line)
    if "msgid" in current and "msgstr" in current:
        entries[current["msgid"]] = current["msgstr"]
    return entries


def write_mo(entries, path):
    keys = sorted(entries)
    ids = [k.encode("utf-8") for k in keys]
    strs = [entries[k].encode("utf-8") for k in keys]
    header_size = 7 * 4
    o_table = header_size
    t_table = o_table + len(keys) * 8
    data_start = t_table + len(keys) * 8
    offsets, data = [], b""
    for b in ids:
        offsets.append((len(b), data_start + len(data)))
        data += b + b"\0"
    t_offsets = []
    for b in strs:
        t_offsets.append((len(b), data_start + len(data)))
        data += b + b"\0"
    out = struct.pack("<7I", 0x950412DE, 0, len(keys), o_table, t_table, 0, 0)
    out += b"".join(struct.pack("<2I", *o) for o in offsets)
    out += b"".join(struct.pack("<2I", *o) for o in t_offsets)
    path.write_bytes(out + data)


for po in sorted(LANG_DIR.glob("*.po")):
    entries = parse_po(po)
    write_mo(entries, po.with_suffix(".mo"))
    print(f"{po.name}: {len(entries) - 1} strings -> {po.with_suffix('.mo').name}")
