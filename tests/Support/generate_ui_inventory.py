"""Capture the pre-refresh Blade interaction surface. Run before editing views."""

import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[2]
views = root / "resources/views/livewire"
inventory = {}


def matches(pattern, source):
    return sorted(set(re.findall(pattern, source, re.S)))


for path in sorted(views.rglob("*.blade.php")):
    source = path.read_text(encoding="utf-8")
    name = path.relative_to(root).as_posix()
    inventory[name] = {
        "wire": matches(r'\b(wire:(?:model(?:\.[\w-]+)*|click|submit|key))\s*=\s*["\']([^"\']*)["\']', source),
        "errors": matches(r'@error\(\s*["\']([^"\']+)["\']\s*\)', source),
        "can": matches(r'@(?:can|cannot)\(\s*([^)]*)\)', source),
        "role_conditions": matches(r'@(?:if|elseif)\s*\(([^\n]*?(?:primary_role|->can\(|hasRole)[^\n]*?)\)\s*(?:\n|<|@)', source),
        "permission_calls": matches(r'auth\(\)->user\(\)->can\([^)]*\)', source),
        "permission_conditions": matches(r'@(?:if|elseif)\(([^)]*\$canManage[^)]*)\)', source),
        "file_bindings": matches(r'<input\b[^>]*\btype\s*=\s*["\']file["\'][^>]*>', source),
        "routes": matches(r'\broute\(\s*["\']([^"\']+)["\']', source),
        "links": matches(r'\b(?:href|action|back)\s*=\s*["\'](/[^"\']*)["\']', source),
    }
    inventory[name]["file_bindings"] = sorted(set(
        tuple(matches(r'\b(wire:[\w.:-]+)\s*=\s*["\']([^"\']*)["\']', tag))
        for tag in inventory[name]["file_bindings"]
    ))

destination = root / "tests/fixtures/ui-inventory.json"
destination.parent.mkdir(parents=True, exist_ok=True)
destination.write_text(json.dumps(inventory, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
