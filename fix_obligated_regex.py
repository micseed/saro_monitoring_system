import re

with open('class/saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

pattern = r"""(\$obligatedSql\s*=\s*"\s*SELECT s\.saroId, s\.saroNo\s*FROM saro s\s*WHERE s\.status = 'active'\s*AND \(\s*SELECT COUNT\(\*\) FROM object_code oc\s*JOIN procurement p ON p\.objectId = oc\.objectId\s*WHERE oc\.saroId = s\.saroId)(.*?)(\)\s*=\s*0)"""

replacement = r"""\1 AND p.status != 'cancelled'
              ) > 0
              AND (
                  SELECT COUNT(*) FROM object_code oc
                  JOIN procurement p ON p.objectId = oc.objectId
                  WHERE oc.saroId = s.saroId AND p.status NOT IN ('obligated', 'cancelled')
              \3"""

content = re.sub(pattern, replacement, content, flags=re.DOTALL)

with open('class/saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Updated checkAndAutoUpdateStatus query!")
