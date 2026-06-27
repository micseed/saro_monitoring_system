import re

with open('class/saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

target = """              AND (
                  SELECT COUNT(*) FROM object_code oc
                  JOIN procurement p ON p.objectId = oc.objectId
                  WHERE oc.saroId = s.saroId
              ) > 0
              AND (
                  SELECT COUNT(*) FROM object_code oc
                  JOIN procurement p ON p.objectId = oc.objectId
                  WHERE oc.saroId = s.saroId AND p.status != 'obligated'
              ) = 0"""

replacement = """              AND (
                  SELECT COUNT(*) FROM object_code oc
                  JOIN procurement p ON p.objectId = oc.objectId
                  WHERE oc.saroId = s.saroId AND p.status != 'cancelled'
              ) > 0
              AND (
                  SELECT COUNT(*) FROM object_code oc
                  JOIN procurement p ON p.objectId = oc.objectId
                  WHERE oc.saroId = s.saroId AND p.status NOT IN ('obligated', 'cancelled')
              ) = 0"""

if target in content:
    content = content.replace(target, replacement)
    with open('class/saro.php', 'w', encoding='utf-8') as f:
        f.write(content)
    print("Updated obligatedSql check correctly.")
else:
    print("Could not find target string to replace.")
