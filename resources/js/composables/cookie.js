// A preference the server reads on the first request, which storage cannot give it: the
// page loaded, then asked again. Not encrypted, so bootstrap/app.php names each one it
// reads. A year, the site's whole path, and never sent cross-site.
export function writeCookie(name, value) {
  document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=31536000; SameSite=Lax`
}
