from __future__ import annotations

import base64
import hashlib
from functools import lru_cache

from cryptography.fernet import Fernet, InvalidToken

from app.config import Settings, get_settings


class SecretBoxError(RuntimeError):
    pass


def _fernet_key(material: str) -> bytes:
    candidate = material.strip().encode()
    try:
        if len(base64.urlsafe_b64decode(candidate)) == 32:
            return candidate
    except (ValueError, TypeError):
        pass
    return base64.urlsafe_b64encode(hashlib.sha256(candidate).digest())


class SecretBox:
    def __init__(self, material: str) -> None:
        if not material.strip():
            raise SecretBoxError("AI_SETTINGS_KEY is empty")
        self._fernet = Fernet(_fernet_key(material))

    def encrypt(self, plaintext: str) -> str:
        return self._fernet.encrypt(plaintext.encode()).decode()

    def decrypt(self, token: str) -> str:
        try:
            return self._fernet.decrypt(token.encode()).decode()
        except InvalidToken as exc:
            raise SecretBoxError("stored secret cannot be decrypted (AI_SETTINGS_KEY changed?)") from exc


def secret_box_for(settings: Settings) -> SecretBox:
    material = settings.ai_settings_key
    if not material.strip():
        if settings.is_production:
            raise SecretBoxError("AI_SETTINGS_KEY must be set in production")
        material = f"dev-derived::{settings.signing_secret}"
    return SecretBox(material)


@lru_cache(maxsize=1)
def get_secret_box() -> SecretBox:
    return secret_box_for(get_settings())
