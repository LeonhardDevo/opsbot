import {
    AlarmClock,
    BookOpenText,
    MessageCircleQuestionMark,
    MessagesSquare,
    Siren,
    UserCheck,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';

export type StorySceneKey =
    | 'answer'
    | 'source'
    | 'refusal'
    | 'manager'
    | 'incident'
    | 'reminder';

export const storyScenes: ReadonlyArray<{
    key: StorySceneKey;
    icon: LucideIcon;
}> = [
    { key: 'answer', icon: MessagesSquare },
    { key: 'source', icon: BookOpenText },
    { key: 'refusal', icon: MessageCircleQuestionMark },
    { key: 'manager', icon: UserCheck },
    { key: 'incident', icon: Siren },
    { key: 'reminder', icon: AlarmClock },
];

export function pickActiveScene(
    entries: ReadonlyArray<{ key: StorySceneKey; ratio: number; top: number }>,
    fallback: StorySceneKey,
): StorySceneKey {
    const visible = entries.filter((entry) => entry.ratio > 0);

    if (visible.length === 0) {
        return fallback;
    }

    return visible.reduce((best, entry) =>
        entry.ratio > best.ratio ||
        (entry.ratio === best.ratio && entry.top < best.top)
            ? entry
            : best,
    ).key;
}
