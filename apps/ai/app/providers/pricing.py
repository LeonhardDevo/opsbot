from __future__ import annotations

from decimal import Decimal

from app.providers.types import ChatUsage

PRICES: dict[str, tuple[float, float, float]] = {
    "claude-fable-5-1": (10.0, 50.0, 0.25),
    "claude-fable-5": (10.0, 50.0, 1.0),
    "claude-opus-5-5": (4.0, 20.0, 0.2),
    "claude-opus-5": (5.0, 25.0, 0.5),
    "claude-sonnet-5-5": (2.0, 10.0, 0.2),
    "claude-sonnet-5": (2.0, 10.0, 0.2),
    "claude-haiku-5-5": (0.1, 0.5, 0.01),
    "claude-haiku-4-5": (1.0, 5.0, 0.1),
    "gpt-5-nano": (0.05, 0.4, 0.005),
    "gpt-5-mini": (0.25, 2.0, 0.025),
    "gpt-5": (1.25, 10.0, 0.125),
    "deepseek-chat": (0.14, 0.28, 0.0028),
    "deepseek-v4-flash": (0.14, 0.28, 0.0028),
    "deepseek/deepseek-v4-flash": (0.14, 0.28, 0.0028),
}

_ORDERED_KEYS = sorted(PRICES, key=len, reverse=True)


def price_for(model: str) -> tuple[float, float, float] | None:
    lowered = model.lower()
    if lowered.endswith((":free", "-free")):
        return None
    for key in _ORDERED_KEYS:
        if key in lowered:
            return PRICES[key]
    return None


def estimate_cost_usd(model: str, usage: ChatUsage) -> Decimal:
    price = price_for(model)
    if price is None:
        return Decimal(0)
    fresh_prompt = max(0, usage.prompt_tokens - usage.cached_tokens)
    total = fresh_prompt * price[0] + usage.completion_tokens * price[1] + usage.cached_tokens * price[2]
    return (Decimal(str(total)) / Decimal(1_000_000)).quantize(Decimal("0.000001"))
