# Remote Downloader

A single PHP file that downloads big files from any URL straight to your cPanel / shared-hosting
server. It shows a live AJAX progress bar (percent, size, speed, time left) and doesn't hit
PHP or web-server timeouts.

## Install

1. Open `index.php` and change `APP_PASSWORD` at the top. The page won't run until you do.
2. In cPanel → **File Manager**, create a folder such as `public_html/dl/` and upload `index.php` into it.
3. Open `https://your-domain.com/dl/` and log in.
4. Paste a direct download link and press **Download to server**.

Finished files go into `dl/downloads/`. You can download them to your computer from the page, or
open them in File Manager.

Requirements: PHP 7.4 or newer with the cURL extension, which is on by default in cPanel.

## How it avoids timeouts

Hosts usually stop a PHP request after 30–300 seconds. Proxies like Cloudflare stop at about 100 seconds.
A multi-gigabyte download takes much longer than that, so the script never downloads the whole
file in one request:

| Mode | When it's used | How it works |
|------|----------------|--------------|
| **Chunked** (default) | The remote server supports HTTP Range (resume). Most file hosts do. | Each AJAX request downloads for `CHUNK_SECONDS` (20 s), appends to a `.part` file and returns. The browser then asks for the next slice. No request runs long enough to time out. You can pause, resume, recover after a network drop, or close the tab and press **Resume** later. |
| **Background** | The server can't resume, or you tick *"Keep downloading after I close this page"*. | PHP replies to the browser, detaches (`fastcgi_finish_request` / `litespeed_finish_request`), and keeps downloading with no time limit. The page polls the progress. |

Some shared hosts kill long-running background processes. If that happens, the download shows
**Interrupted**. If the file is resumable, press **Resume** to continue in chunked mode from
where it stopped.

## Configuration (top of `index.php`)

| Setting | Default | Notes |
|---------|---------|-------|
| `APP_PASSWORD` | `change-me` | Required. |
| `DOWNLOAD_DIR` | `__DIR__ . '/downloads'` | To keep files private, use a folder outside `public_html`, e.g. `/home/USER/downloads`. |
| `CHUNK_SECONDS` | `20` | Keep it well below your host's timeout. |
| `USER_AGENT` | Chrome UA | Some servers refuse unknown clients. |

Under **Options** you can also set the file name, add request headers (`Cookie:`, `Referer:`,
`Authorization:` for links that need a login), turn on background mode, or ignore SSL errors.

## Security notes

- Every action needs the password session and a CSRF token. Only `http`/`https` URLs are accepted.
- The script writes an `.htaccess` into the download folder. It turns off directory listing,
  blocks PHP/CGI execution and sandboxes HTML files. Executable names like `shell.php` are
  also saved as `shell.php.txt`.
- Temporary files and job state live in `downloads/.jobs/`, and an `.htaccess` blocks web access to it.
- On **nginx-only** hosts, `.htaccess` files are ignored. Point `DOWNLOAD_DIR` outside the web root there.
