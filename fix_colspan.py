with open('saro/cancelled_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace('colspan="7"', 'colspan="8"')

with open('saro/cancelled_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)
print("Fixed cancelled colspan")
