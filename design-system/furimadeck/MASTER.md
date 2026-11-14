# FurimaDeck UI Design System

## Product and users

FurimaDeck is a Japanese resale operations SaaS. Its everyday users register items, prepare marketplace listings, record sales, and review inventory and profit. The interface is a web application used on desktop and phones.

## Visual direction

- Use a clear, flat business interface with a light work surface and a deep slate navigation bar.
- Use slate for structure and text, blue for links and keyboard focus, and dark emerald for positive actions and stock-related emphasis.
- Keep page content on distinct white surfaces with thin slate borders and restrained shadows.
- Keep the existing dark marketing hero as a separate public-site treatment; shared typography, focus, controls, and responsive spacing still apply.
- Use Japanese-capable system fonts: `Inter`, `Noto Sans JP`, `Yu Gothic UI`, `Meiryo`, `system-ui`, `sans-serif`. Do not require a remote font service.
- Avoid decorative gradients, blurred glass surfaces, busy photographic backgrounds behind forms, emoji icons, and low-contrast muted text.

## Core tokens

| Role | Value |
| --- | --- |
| Primary slate | `#334155` |
| Main text | `#0F172A` |
| Secondary text | `#475569` |
| Accent emerald | `#047857` |
| Canvas | `#F8FAFC` |
| Surface | `#FFFFFF` |
| Border | `#E2E8F0` |
| Form border | `#CBD5E1` |
| Focus | `#2563EB` |
| Destructive | `#B91C1C` |
| Info text | `#155E75` |
| Success text | `#065F46` |
| Warning text | `#92400E` |
| Violet text | `#5B21B6` |
| Info surface | `#ECFEFF` |
| Success surface | `#ECFDF5` |
| Warning surface | `#FFFBEB` |
| Danger surface | `#FEF2F2` |
| Violet surface | `#F5F3FF` |

## Layout and interaction

- Start at phone width; use one column by default and add columns at `sm`, `md`, `lg`, and `xl` only when content benefits.
- Use 16px page gutters on phones and wider gutters from tablet sizes onward.
- Keep forms and buttons at least 44px tall. On phones, form controls use 16px text to avoid browser zoom.
- Tables remain semantic and scroll horizontally inside their containing surface on narrow screens.
- Use visible keyboard focus, labels tied to controls, descriptive names for icon-only controls, and text alongside state colors.
- Respect `prefers-reduced-motion`; use short color and opacity transitions without moving layout.
- Keep navigation reachable with keyboard, touch, and browser back behavior. Mobile menus scroll within the viewport.

## Page families

- Authenticated application: deep slate navigation, white page title area, light slate canvas, white content cards.
- Authentication and account recovery: light canvas, centered white form surface, optional product imagery only in the wide-screen side panel.
- Marketing: retain the established dark hero and its light-green accent while preserving readable content widths and mobile navigation.
- Legal and help pages: light canvas, readable article width, strong heading hierarchy, and full-width mobile navigation wrapping.
