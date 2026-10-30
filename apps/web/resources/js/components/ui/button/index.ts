import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "press inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-control text-[0.9375rem] font-semibold disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-[1.125rem] shrink-0 [&_svg]:shrink-0 outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-clay-sm hover:bg-[color-mix(in_oklch,var(--primary)_90%,var(--fg))]",
        destructive:
          "bg-destructive text-destructive-foreground shadow-clay-sm hover:bg-[color-mix(in_oklch,var(--destructive)_90%,var(--fg))]",
        outline:
          "border border-border bg-surface text-foreground shadow-clay-sm hover:bg-surface-2",
        secondary:
          "border border-border bg-surface text-foreground shadow-clay-sm hover:bg-surface-2",
        soft:
          "bg-primary-soft text-primary-strong hover:bg-[color-mix(in_oklch,var(--primary-soft)_85%,var(--primary))]",
        mint:
          "bg-mint text-mint-foreground shadow-clay-sm hover:bg-[color-mix(in_oklch,var(--accent-mint)_88%,var(--fg))]",
        ghost:
          "text-foreground hover:bg-surface-2",
        link: "h-auto px-0 text-primary underline-offset-4 hover:underline",
      },
      size: {
        "default": "h-11 px-[18px] has-[>svg]:px-4",
        "sm": "h-9 rounded-[12px] gap-1.5 px-3.5 text-sm pointer-coarse:min-h-11 has-[>svg]:px-3",
        "lg": "h-12 px-6 text-base has-[>svg]:px-5",
        "icon": "size-11",
        "icon-sm": "size-9 rounded-[12px] pointer-coarse:size-11",
        "icon-lg": "size-12",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
