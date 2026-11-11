# Passengers in the unified Mini App

Keep the neutral palette, Vazirmatn, existing cards and bottom sheets. Add one
People destination to the bottom navigation; inside it, a two-option moving
selector switches saved travelers and passenger lists. Booking contact details
are a compact adjacent entry, not another top-level navigation item.

    [ سفرها | سفر جدید | مسافران | هشدارها ]
    مسافران                           تازه‌سازی
    [ مسافران سابق | لیست‌های مسافران ]
    جست‌وجو                          + افزودن
    نام مسافر / نام لیست
    گروه سنی، جنسیت، ویلچر / تعداد نفر و صندلی
    کد ملی پوشیده / خلاصهٔ اعضا          ویرایش · حذف
    اطلاعات تماس رزرو

| Existing bot capability | Mini App location |
| --- | --- |
| Add/name/national code/age/gender/wheelchair | Traveler form |
| View and delete saved travelers | Saved travelers, search and confirmed deletion |
| Create/view/delete lists | Lists tab and editor |
| Add existing travelers to a list | Independent membership switches |
| Booking with those lists | Same IDs and database; existing booking callbacks retained |
| Buyer name/mobile/optional email | Booking contact editor |

Also allow editing a saved traveler, renaming a list and removing its members.
Creating a traveler while editing a list returns to that draft and selects the
new traveler. Deleting a list keeps its travelers; deleting a traveler detaches
only that account's memberships. Infant count and seat count remain distinct.
Preserve inactive legacy services without exposing implementation JSON in forms.

Reuse both historical membership formats in place. Signed owner checks apply
to every API action. Content versions protect against edits through the old bot
as well as other Mini App sessions. Mutations have durable idempotency keys;
store only request hashes/results, never identity payloads in that ledger. Do
not persist passenger identity/contact drafts in browser storage or send them
to external services. Membership/identity edits invalidate affected unfinished
local booking/food drafts so a food index cannot silently select another person.
