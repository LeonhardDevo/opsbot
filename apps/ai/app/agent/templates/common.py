from __future__ import annotations

import re

_MARKDOWN_SPECIAL = re.compile(r"([\\`*_\[\]<>#|])")


def md_escape(value: object) -> str:
    text = " ".join(str(value).split())
    return _MARKDOWN_SPECIAL.sub(r"\\\1", text)
