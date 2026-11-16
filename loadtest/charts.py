"""Draws the two charts in REPORT.md from loadtest/results/after-*.json and after2-*.json.

Python 3 standard library only: python loadtest/charts.py
"""
import glob
import json
import os
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
SIZES = [("S", 16), ("M", 80), ("L", 200)]
SERIES = {
    "off": ("#c2410c", "square", "off, first fix", "after"),
    "on": ("#2563eb", "circle", "on, first fix", "after"),
    "on2": ("#0f766e", "diamond", "on, final fix", "after2"),
}
OFFSETS = {"off": -34, "on": 0, "on2": 34}
INK, MUTED, GRID = "#1f2937", "#6b7280", "#e5e7eb"


def load():
    cells = defaultdict(list)
    for prefix in ("after", "after2"):
        path = sorted(glob.glob(os.path.join(HERE, "results", prefix + "-*.json")))[-1]
        for run in json.load(open(path))["runs"]:
            key = run["cell"] + ("2" if prefix == "after2" else "")
            cells[key].append(run)
    return cells


def nice_step(v):
    for step in (10, 20, 25, 50, 100, 200, 250, 500, 1000):
        if v / step <= 5:
            return step
    return 1000


def chart(cells, title, ylabel, value, fname):
    w, h, left, right, top, bottom = 640, 380, 70, 20, 60, 56
    ys = [value(r) for rs in cells.values() for r in rs]
    step = nice_step(max(ys))
    ymax = step * (int(max(ys) / step) + 1)
    pw, ph = w - left - right, h - top - bottom

    def px(i, off):
        return left + pw * (i + 0.5) / len(SIZES) + off

    def py(v):
        return top + ph * (1 - v / ymax)

    o = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}" role="img" aria-label="{title}">',
         f'<title>{title}</title>',
         f'<rect width="{w}" height="{h}" fill="#ffffff"/>',
         f'<text x="{left}" y="24" font-family="system-ui,sans-serif" font-size="15" font-weight="600" fill="{INK}">{title}</text>']
    for t in range(ymax // step + 1):
        v = step * t
        y = py(v)
        o.append(f'<line x1="{left}" x2="{w - right}" y1="{y:.1f}" y2="{y:.1f}" stroke="{GRID}"/>')
        o.append(f'<text x="{left - 8}" y="{y + 4:.1f}" text-anchor="end" font-family="system-ui,sans-serif" font-size="11" fill="{MUTED}">{v:g}</text>')
    o.append(f'<text x="14" y="{top + ph / 2}" transform="rotate(-90 14 {top + ph / 2})" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" fill="{MUTED}">{ylabel}</text>')
    for i, (name, staff) in enumerate(SIZES):
        o.append(f'<text x="{px(i, 0):.1f}" y="{h - bottom + 20}" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" fill="{INK}">{name}: {staff} staff</text>')
    # legend
    lx = left
    for k, (key, (color, shape, label, _)) in enumerate(SERIES.items()):
        x = lx + k * 150
        o.append(marker(shape, x, 40, color, 5))
        o.append(f'<text x="{x + 10}" y="44" font-family="system-ui,sans-serif" font-size="12" fill="{INK}">{label}</text>')
    o.append(f'<text x="{left}" y="{h - 8}" font-family="system-ui,sans-serif" font-size="11" fill="{MUTED}">One mark per run; bar spans min to max, tick is the median. Final fix: broadcasting on only.</text>')
    for key, (color, shape, _, _) in SERIES.items():
        off = OFFSETS[key]
        for i, (name, _) in enumerate(SIZES):
            cell = f"{name.lower()}-{key}".replace("on2", "on") + ("2" if key == "on2" else "")
            vals = sorted(value(r) for r in cells[cell])
            x = px(i, off)
            o.append(f'<line x1="{x:.1f}" x2="{x:.1f}" y1="{py(vals[0]):.1f}" y2="{py(vals[-1]):.1f}" stroke="{color}" stroke-width="2" opacity="0.5"/>')
            o.append(f'<line x1="{x - 9:.1f}" x2="{x + 9:.1f}" y1="{py(vals[1]):.1f}" y2="{py(vals[1]):.1f}" stroke="{color}" stroke-width="2"/>')
            for j, v in enumerate(vals):
                o.append(marker(shape, x + (j - 1) * 6, py(v), color, 3.5))
    o.append("</svg>")
    with open(os.path.join(HERE, "charts", fname), "w", newline="\n") as f:
        f.write("\n".join(o) + "\n")


def marker(shape, x, y, color, r):
    if shape == "circle":
        return f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r}" fill="{color}"/>'
    if shape == "diamond":
        return f'<polygon points="{x:.1f},{y - r - 1:.1f} {x + r + 1:.1f},{y:.1f} {x:.1f},{y + r + 1:.1f} {x - r - 1:.1f},{y:.1f}" fill="{color}"/>'
    return f'<rect x="{x - r:.1f}" y="{y - r:.1f}" width="{2 * r}" height="{2 * r}" fill="{color}"/>'


if __name__ == "__main__":
    cells = load()
    chart(cells, "Call-next p95 latency by load (ms, lower is better)", "milliseconds", lambda r: r["call_next_ms"]["p95"], "call-next-p95.svg")
    chart(cells, "Requests served per second (higher is better)", "requests per second", lambda r: r["requests_per_s"], "throughput.svg")
