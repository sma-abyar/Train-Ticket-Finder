# Final UI pass: time range and loading

Keep the neutral tokens from MOTION.md: white #fff, surface #f5f6f8, ink
#181b20, secondary #646b76, border #e1e4e9; dark canvas #202329. Keep Vazirmatn.
Use the existing bottom-sheet shape and selected surface, rather than a new
analogue clock or a dense grid of sixty minute buttons.

    [ از ساعت  ۲۲:۰۰ ] [ تا ساعت  ۰۶:۰۰ ]
                 +             +
                ساعت          دقیقه
                 ۲۲     :      ۰۰
                 −             −
              [۰۰] [۱۵] [۳۰] [۴۵]
    بازهٔ شبانه در تاریخ همین سفر
    [ انصراف ] [ تأیید بازه ]

Hour/minute controls accept Persian, Arabic and English digits. The endpoint
switch edits working values; confirmation applies both endpoints together.
Cancel, Escape, native Back and the close button preserve the original filter.
Allow 24:00 only as an end boundary. Equal endpoints remain a full day, matching
the existing backend. Validate before applying, and explain overnight ranges.
Keep the underlying HH:mm filter values and existing saved subscriptions.

Loading is honest and contextual: a small train and route skeleton at startup,
train-card placeholders for the initial inventory fetch, a compact progress
status during refresh, and a busy save button. Never add artificial delays,
fake percentages, or block completion until an animation finishes. Hide the
unready navigation; restore meaningful content/actions on errors. Preserve the
existing success screen during the independent first trip check. Respect reduced
motion and retain readable status text when animations are disabled.

Review: one coherent clock editor replaces browser-dependent AM/PM fields.
The loading layout reflects the content being loaded, without adding banners,
extra colors, or new service calls.
