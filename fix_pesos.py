import glob

for pattern in ['saro/*.php', 'admin/*.php']:
    for f in glob.glob(pattern):
        with open(f, 'r', encoding='utf-8') as file:
            content = file.read()
        
        changed = False
        
        if '₱' in content:
            content = content.replace('₱', '&#8369;')
            changed = True
            
        if changed:
            print("Fixed Pesos in:", f)
            with open(f, 'w', encoding='utf-8') as file:
                file.write(content)
