import os, glob

def fix_file(filepath):
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()
    except Exception:
        return
    
    # Reverse double-encoded UTF-8
    try:
        content = content.encode('cp1252').decode('utf-8')
    except Exception:
        pass
    
    # Specific known corruptions
    content = content.replace('â€”', '—')
    content = content.replace('â”€', '─')
    content = content.replace('â€¢', '•')
    content = content.replace('â€œ', '“')
    content = content.replace('â€', '”')
    content = content.replace('â€¦', '...')
    
    content = content.replace('\ufffd', '—')

    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)

for pattern in ['saro/*.php', 'admin/*.php']:
    for f in glob.glob(pattern):
        fix_file(f)
