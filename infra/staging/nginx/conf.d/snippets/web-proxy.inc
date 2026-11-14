# Shared by the three web-app server blocks in staging.conf. $upstream is set
# by the block that includes this file. Not named *.conf so conf.d does not
# load it on its own.
if ($hara_trusted_peer = 0) { return 444; }

limit_req zone=hara_web burst=60 nodelay;

# The apps send their own security headers. Added here: HSTS for the web hosts.
# One week while this is staging; no includeSubDomains, no preload.
add_header Strict-Transport-Security "max-age=604800" always;

location / {
    proxy_pass $upstream;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-Host "";
    proxy_set_header Connection "";
    proxy_read_timeout 35s;
}
