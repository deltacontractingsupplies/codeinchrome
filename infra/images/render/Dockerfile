# The page renderer for the link scanner (audit A15): Chromium, headless,
# as an unprivileged user. Run with every privilege taken away (agent
# render.go); the container, not Chromium's own sandbox, is the boundary.
FROM debian:bookworm-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends chromium ca-certificates fonts-liberation \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --uid 10001 --create-home --shell /usr/sbin/nologin render
USER render
WORKDIR /home/render
ENTRYPOINT ["chromium", "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage", "--no-first-run", "--disable-extensions", "--disable-background-networking", "--disable-sync", "--mute-audio", "--hide-scrollbars", "--user-data-dir=/tmp/profile"]
