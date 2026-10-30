import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-chip border px-2.5 py-0.5 text-xs font-semibold w-fit whitespace-nowrap shrink-0 [&>svg]:size-3.5 gap-1 [&>svg]:pointer-events-none focus-visible:outline-3 focus-visible:outline-ring transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary-soft text-primary-strong",
        secondary:
          "border-transparent bg-neutral-soft text-neutral-foreground",
        destructive:
          "border-transparent bg-coral-soft text-coral-strong",
        success:
          "border-transparent bg-success-soft text-success",
        warning:
          "border-transparent bg-sun-soft text-sun-strong",
        mint:
          "border-transparent bg-mint-soft text-mint-strong",
        outline:
          "border-border text-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
