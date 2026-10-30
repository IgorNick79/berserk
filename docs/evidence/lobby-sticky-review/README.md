# Lobby visual review

Screenshots and checks use synthetic room/account fixtures, not a live authenticated PHP/DB session. Production CSS and JavaScript are loaded by tools/review_lobby_redesign.cjs.

Run with Node.js, Playwright and installed Microsoft Edge:

    node tools/review_lobby_redesign.cjs

PLAYWRIGHT_MODULE may point to an external Playwright installation.

Browser checks cover 1600x900, 1366x768, 390x844 and 360x800, actual sticky scrolling, first/last card expansion, tabs, menu viewport bounds and stacking, and JavaScript errors. Real profile/logout, room creation/joining and authorization were not integration-tested. PHP 8.1 lint and git diff --check passed.
