import re

def add_css(filename):
    with open(filename, 'r', encoding='utf-8') as f:
        content = f.read()

    css_to_add = """
.action-btn {
    width: 30px; height: 30px; border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid transparent; cursor: pointer;
    background: transparent; transition: all 0.2s ease;
}
.action-btn-del { color: #94a3b8; }
.action-btn-del:hover { background: #fee2e2; border-color: #fecaca; color: #dc2626; }
"""
    if 'width: 30px; height: 30px; border-radius: 7px;' not in content:
        # replace the first </style> which is in the head
        content = content.replace('</style>', f'{css_to_add}</style>', 1)
        with open(filename, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Added CSS to {filename}")

add_css('saro/obligated_saro.php')
add_css('saro/lapsed_saro.php')
add_css('saro/cancelled_saro.php')
