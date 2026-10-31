# Design System: Liqaa Tutoring Administration

## 1. Visual Theme & Atmosphere
An Arabic-first, right-to-left workspace for a tutoring platform's company administrator. Calm, precise and practical: density 6/10, variance 5/10, motion 3/10. Prioritize payment reviews, student access and scheduled learning over decorative analytics. All preview names, counts and transactions are fictional design samples.

## 2. Color Palette & Roles
- Canvas Zinc (#FAFAFA): page background.
- Surface White (#FFFFFF): primary workspace and elevated overlays.
- Charcoal Ink (#18181B): headings and primary text; never pure black.
- Secondary Zinc (#52525B): supporting text.
- Quiet Line (#E4E4E7): structural borders.
- Deep Teal (#20756E): the sole accent for the main action, selection and focus.
- Teal Wash (#EDF6F4): selected navigation and subtle emphasis.
Use text and icons as well as color for statuses. No gradients, neon or purple glow.

## 3. Typography Rules
Arabic UI: IBM Plex Sans Arabic, sans-serif; Latin fallback: Geist, sans-serif. Numeric references: Geist Mono with tabular figures. Headings 24–30px, section titles 18px, body 16px, metadata at least 14px. Arabic line-height 1.65. Avoid negative tracking on connected Arabic script. No serif or Inter. Isolate Latin IDs, currency amounts and times with LTR direction.

## 4. Component Stylings
Buttons have 10px corners, 44px minimum touch targets and clear focus rings. One filled primary action per workspace. Secondary actions use quiet outlines. Active feedback uses a subtle transform.
Use 16px corner surfaces only to separate meaningful work areas. Prefer dividers for metric summaries and table rows; avoid a grid of identical cards.
Inputs have persistent labels above, helper text and inline errors below. Tables have readable headers, row actions and explicit status labels. Destructive actions need a clearly worded confirmation dialog.
Skeletons match the final layout. Empty states explain what to do next. Errors include retry controls and retain filters. Do not use generic spinners or decorative stock photography.

## 5. Layout Principles
Desktop: 248px navigation on the RIGHT, compact utility header, content max-width 1400px, 32px gutters. Navigation: نظرة عامة، الطلاب، المدرّسون، المجموعات، الجلسات، المدفوعات، الاشتراكات، المحتوى التعليمي، الإعدادات.
Use an asymmetric main grid: wide payment-review table and narrower upcoming-session list. Above it, a single divided strip of four metrics. The greeting is compact and right aligned, not a marketing hero. Keep text and imagery in separate zones; no overlapping elements.
Below 768px, collapse all content to one column, replace the sidebar with an accessible menu and stack metric summaries. Transform table rows to labeled stacked items without horizontal scrolling. Use CSS Grid and logical spacing properties. Minimum height 100dvh; never fixed screen height. Touch targets at least 44px.

## 6. Motion & Interaction
Use transform and opacity only, 160–220ms feedback and optional spring settings stiffness 100, damping 20. A subtle opacity loop is reserved for a genuinely ongoing session and shimmer for loading; avoid continuous motion on static information. Stagger initial rows by 30ms with a short total duration. Respect prefers-reduced-motion, disabling loops and stagger. Keyboard navigation, visible focus and accessible names are mandatory.

## 7. Anti-Patterns (Banned)
No emojis, pure black, Inter, serif dashboard typography, neon, oversaturated accents, gratuitous gradients, custom cursors, overlapping content, three equal feature cards, huge hero headlines, filler marketing copy, fabricated performance claims, generic English placeholder identities or broken external images. Do not portray sample metrics as real backend data. Do not imply an admin analytics endpoint exists: verify API coverage before implementation.

## 8. Stitch Screen Brief
Generate a coherent responsive Arabic RTL web dashboard for لقاء, a tutoring platform. Start with the company administrator overview, payment review screen and student management screen, plus a mobile overview. Use the system above consistently.
Overview: title نظرة عامة; brief greeting; date filter; primary action إضافة طالب. A divided summary strip: إجمالي الطلاب ١٬٢٨٤، المدرّسون ٨٦، الجلسات القادمة ٢٣، دفعات بانتظار المراجعة ١٧. Students total 1,284 comprises 1,214 active accounts and 70 suspended accounts; subscription status is independent. A prominent review section title دفعات تحتاج مراجعتك with columns الطالب، المدرّس، المرجع، تاريخ الطلب، الحالة، الإجراء. Use fictional Arabic names. Include upcoming sessions with subject, teacher, group, time and explicit state. Place secondary subscription-renewal notices below. Show a visible بيانات تجريبية label.
Payments screen: search, date and status filters, clear selected transaction detail, proof-of-payment preview placeholder, approve/reject controls and rejection-reason dialog. Do not assume a currency; label a demonstrative amount with عملة المنصة.
Students screen: search and status filters, student list, active/suspended status, details action and explicit suspension confirmation. Include a useful empty search state.
Deliver polished visual screens, consistent components and readable Arabic text, not a marketing landing page.

## 9. Review and Canvas Maintenance
Stitch project: https://stitch.withgoogle.com/projects/14842199783773690179
The user authorized continuation beyond the initial five screens. Preserve 01 overview, 02 payments, 03 students, 04 mobile overview and 05 payment detail, plus the design-system reference. Add the distinct screens in the inventory below. Select an existing screen before editing. If Stitch creates a replacement, inspect it and delete the superseded screen. Do not retain redundant variants or obsolete prototypes.
The mobile screen itself is 390px wide, without a desktop sidebar, fake phone frame or simulated OS status bar. Account-status filters must use the account counts above. Sample transaction: TRX-94821, عبدالله الشمري, 350.00 عملة المنصة. Year 2026. Receipt status: إيصال مرفوع — بانتظار التحقق. Never claim bank verification, successful payment or electronic certification without actual evidence. No assumed bank brands or currency.
Use linked label/for/id fields, Arabic accessible button names, decorative icons hidden from assistive technology, visible keyboard focus and controls at least 44px. Destructive account actions and payment decisions must explain their consequence with a clear cancel path.

## 10. Approved Continuation Inventory
06 teachers; 07 teacher profile; 08 add teacher; 09 groups; 10 group detail; 11 create group; 12 session calendar/list; 13 session detail and attendance; 14 schedule session; 15 teacher subscriptions; 16 subscription plans; 17 edit plan; 18 curriculum browser; 19 content editor; 20 quiz editor; 21 general settings; 22 payment settings; 23 student profile; 24 add student.
These are design mockups, not claims of implemented administration endpoints. Align forms with repository entities: teacher specialization/bio/experience, groups linked to teacher subjects and capacity, GROUP versus INDIVIDUAL sessions with mutually exclusive group/enrollment links, teacher subscription plans and dates, teacher subject → unit → lesson → content hierarchy. Subscription plans include billing cycle, commission rate and resource limits. Payment settings are Sham Cash, with account ID and QR image; use only fictional example data. Keep financial currencies consistent with existing mockups until final localization, and indicate configured SYP in payment settings without assuming Saudi banking.
Preserve one canvas screen per inventory item. Confirmation, error and empty states belong inside their relevant screen or dialog, not extra duplicate screens. No additional prototype canvas unless requested.

## 11. Navigation Cleanup Verified — 2026-10-31
Edited the existing screen elements directly in Stitch; no screens were generated or replaced. Verified a fresh independent project load: 24 screen nodes remain, and desktop sidebars have nine primary sections: overview, students, tutors, groups, sessions, payments, subscriptions, educational-content, settings. Removed teacher-subscriptions/subscription-plans root duplicates and numbered detail links 18–24 from affected screens. Detail screens remain on the canvas. Mobile screen retained its existing layout and hidden desktop sidebar. Verification used freshly loaded rendered previews and source markup, not chat success claims.

## 12. Remaining Administration Screens — 2026-10-31
User confirmed scope: missing administration interfaces only. Extend inventory with 25 refund requests; 26 refund review and manual-transfer completion; 27 student enrollments; 28 paid bookings; 29 global subject catalog; 30 governorates/regions/schools; 31 read-only audit log; 32 standalone admin login. Preserve screens01–24. New screens use the same nine root navigation entries and local section tabs, not new numbered sidebar links. Drawers/dialogs contain add/edit, empty, error and confirmation states without extra canvas screens.
Refund approval and completion are distinct: actual repository RefundController requires review_note, and completion requires a unique outgoing transfer_reference different from original payment reference after a manual transfer. Approval can cancel linked booking/session; completion cancels linked enrollment, upcoming sessions and group membership. Design mockups never execute financial actions. CRUD proposals for model-backed catalogs are design scope, not claims that admin endpoints exist.
