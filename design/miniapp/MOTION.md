# Selection and motion, version 15

Keep the train app's existing neutral palette and Vazirmatn type hierarchy.
Light: paper #ffffff, surface #f5f6f8, text #181b20, secondary #646b76,
border #e1e4e9. Dark: canvas #202329, surface #2b2f37, text #f0f2f6.
The selected pill is white in light mode and #454b55 in dark mode.
Remove blue from selection, switches, focus outlines and active navigation.
Preserve a clear keyboard focus outline and readable selected/unselected states.

RTL single-selection groups share one moving indicator:

    [ خواهران | برادران | عادی ]
                  <--- pill moves, stretches slightly, settles

The marker uses actual element bounds, not a left-to-right index assumption.
Only the selection marker is elastic; the labels stay still. Multiple-choice
train types retain independent selection surfaces. This is a travel form, so
do not turn every card, paragraph or alert into a bouncing element.

| Screen/action | Treatment |
| --- | --- |
| Route/date | Moving quick-date pill, short swap rotation, calendar press feedback |
| Preferences | 340 ms elastic quota marker, neutral switches, 180 ms count response |
| Review/back | 170 ms directional transition of the current panel |
| Trip list/navigation | Moving active navigation surface; no staggered card entrances |
| Train alerts | Moving local filter pill, switch thumb response, clear confirmed/pending status |
| City/calendar/delete sheets | Short upward reveal on opening; immediate close |
| Saved trip | Small one-time check response after registration |

Review: the palette stays neutral and motion follows the user's action. The
quota indicator is the one expressive element; page motion stays under 8 px.
No animation delays saving, navigation, message delivery or reservation actions.
No third-party dependency or added inventory/API request. Cancel an interrupted animation and resume
from its rendered position. Re-measure on resize/font load, and support reduced
motion both in CSS and JavaScript.

Implementation references: [Element.animate](https://developer.mozilla.org/en-US/docs/Web/API/Element/animate)
and [prefers-reduced-motion](https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion).
