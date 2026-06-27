with open('saro/cancelled_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

target = "$codeCount = (int)$s['obj_count'];"
replacement = target + "\n                                $isOwner = (int)$s['userId'] === $userId;"

if "$isOwner = " not in content:
    content = content.replace(target, replacement)

with open('saro/cancelled_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)
print("Fixed cancelled_saro isOwner definition")
