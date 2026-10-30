from __future__ import annotations

INCIDENT_CATEGORIES = ("damage", "complaint", "no_access", "injury", "lost_item", "other")
ROUTING_CATEGORIES = (*INCIDENT_CATEGORIES, "question", "task")

CATEGORY_LABELS: dict[str, dict[str, str]] = {
    "damage": {"en": "damage", "ru": "повреждение имущества"},
    "complaint": {"en": "client complaints", "ru": "жалобы клиентов"},
    "no_access": {"en": "no access to the property", "ru": "нет доступа на объект"},
    "injury": {"en": "injuries", "ru": "травмы"},
    "lost_item": {"en": "lost items", "ru": "пропажи и потерянные вещи"},
    "other": {"en": "other incidents", "ru": "прочие инциденты"},
    "question": {"en": "questions", "ru": "вопросы"},
    "task": {"en": "tasks", "ru": "задачи"},
}

INCIDENT_CATEGORY_TITLES: dict[str, dict[str, str]] = {
    "damage": {"en": "Damage", "ru": "Повреждение"},
    "complaint": {"en": "Complaint", "ru": "Жалоба"},
    "no_access": {"en": "No access", "ru": "Нет доступа"},
    "injury": {"en": "Injury", "ru": "Травма"},
    "lost_item": {"en": "Lost item", "ru": "Пропажа"},
    "other": {"en": "Other", "ru": "Другое"},
}

INCIDENT_STATUSES: dict[str, dict[str, str]] = {
    "open": {"en": "open", "ru": "открыт"},
    "in_progress": {"en": "in progress", "ru": "в работе"},
    "resolved": {"en": "resolved", "ru": "решён"},
    "closed": {"en": "closed", "ru": "закрыт"},
}

TASK_STATUSES: dict[str, dict[str, str]] = {
    "todo": {"en": "to do", "ru": "к выполнению"},
    "in_progress": {"en": "in progress", "ru": "в работе"},
    "done": {"en": "done", "ru": "выполнена"},
    "cancelled": {"en": "cancelled", "ru": "отменена"},
}


def label(table: dict[str, dict[str, str]], key: str, locale: str) -> str:
    entry = table.get(key)
    if entry is None:
        return key
    return entry.get(locale) or entry["en"]


def ru_plural(count: int, one: str, few: str, many: str) -> str:
    tail = abs(count) % 100
    if 11 <= tail <= 14:
        return many
    last = tail % 10
    if last == 1:
        return one
    if 2 <= last <= 4:
        return few
    return many


def count_phrase(count: int, locale: str, forms_ru: tuple[str, str, str], forms_en: tuple[str, str]) -> str:
    if locale == "ru":
        return f"{count} {ru_plural(count, *forms_ru)}"
    return f"{count} {forms_en[0] if count == 1 else forms_en[1]}"
