// Which page / shows, the phone's simple one or the full home page: HomeController::VIEW_COOKIE,
// read on the first request, so a phone does not load the home page to be sent away from it.
const HOME_VIEW_COOKIE = 'home_view'

export function showHomeView(view) {
  writeCookie(HOME_VIEW_COOKIE, view)
  router.visit('/')
}
