import glob, re
for pattern in ['saro/*.php', 'admin/*.php']:
    for f in glob.glob(pattern):
        with open(f, 'r', encoding='utf-8') as file:
            content = file.read()
            
        changed = False
        
        # We know '?<?=' is definitely '₱<?='
        if '?<?=' in content:
            content = content.replace('?<?=', '₱<?=')
            changed = True
            
        # Also let's check for literal '?0.00' which is '₱0.00'
        if '?0.00' in content:
            content = content.replace('?0.00', '₱0.00')
            changed = True
            
        # Also in JS: '?' + Number...
        if "'?' +" in content:
            content = content.replace("'?' +", "'₱' +")
            changed = True
            
        # Any other '?' inside <span ...> ?
        # e.g. >?<?=
        
        if changed:
            print("Fixed symbols in", f)
            with open(f, 'w', encoding='utf-8') as file:
                file.write(content)
