for path in ['saro/lapsed_saro.php', 'saro/obligated_saro.php']:
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    content = content.replace('<?= $saroNoEsc ?>', '<?= htmlspecialchars($s[\'saroNo\'], ENT_QUOTES) ?>')
    
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
print("Fixed saroNoEsc")
