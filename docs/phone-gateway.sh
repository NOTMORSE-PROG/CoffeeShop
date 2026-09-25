#!/data/data/com.termux/files/usr/bin/bash
#
# Our Coffee Shop - phone SMS gateway
#
# Runs on the shop phone and sends the order texts from its own SIM.
#
# It only needs a data connection. The screen can be off and the phone can be
# in a pocket on mobile data; it does not need wifi and does not need to be
# awake. It reconnects on its own, so losing signal for a while is fine:
# anything queued while it was away is collected when it comes back.
#
# ---------------------------------------------------------------------------
# Setting it up, once
# ---------------------------------------------------------------------------
#
#   1. Install Termux and Termux:API from F-Droid (not the Play Store, those
#      versions are abandoned).
#
#   2. Open Termux and run:
#
#        pkg update && pkg install termux-api
#        termux-setup-storage
#
#   3. Put this file on the phone and run it once to grant the SMS permission:
#
#        bash phone-gateway.sh
#
#      Android asks for permission to send texts the first time. Allow it.
#
#   4. Keep it awake across reboots and screen-off:
#
#        pkg install termux-services
#        termux-wake-lock
#
#      In Android settings, find Termux under battery and set it to
#      Unrestricted. That single setting is the usual reason a gateway
#      quietly stops after a few hours.
#
# ---------------------------------------------------------------------------

set -uo pipefail

# --- Fill these in ----------------------------------------------------------

# The address from the admin site, Settings, Shop handset.
# While testing on the same wifi this is the computer's address. Once the site
# is hosted it is the real domain, and nothing else here changes.
GATEWAY_URL="http://192.168.1.97/ourcoffee-admin/api/sms-gateway.php"

# The token from that same panel.
TOKEN="paste-your-token-here"

# A name for this handset, so the admin can tell which phone is connected.
DEVICE="shop-phone"

# How often to ask for work while the shop is open, in seconds.
POLL_SECONDS=20

# How often to check while the shop is closed. The server says when it is
# shut, so the phone can go quiet overnight instead of polling for nothing.
CLOSED_POLL_SECONDS=600

# ---------------------------------------------------------------------------

LOG="$HOME/coffee-sms.log"

log() {
    printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" | tee -a "$LOG"
}

# A short timeout so a dead network does not wedge the loop, and a few retries
# so a brief drop does not look like a failure.
fetch() {
    curl --silent --show-error --location \
         --max-time 20 --connect-timeout 10 \
         --retry 2 --retry-delay 2 \
         "$1"
}

if [ "$TOKEN" = "paste-your-token-here" ]; then
    log "Set TOKEN at the top of this file first. Get it from Settings, Shop handset."
    exit 1
fi

if ! command -v termux-sms-send >/dev/null 2>&1; then
    log "termux-sms-send is missing. Run: pkg install termux-api"
    exit 1
fi

log "Gateway started. Asking $GATEWAY_URL every ${POLL_SECONDS}s."
log "Leave this running. The screen can be off."

# Keep the CPU awake so Android does not freeze the loop when the screen goes
# off. Harmless if Termux:API is not installed.
termux-wake-lock 2>/dev/null || true

trap 'log "Gateway stopped."; termux-wake-lock --release 2>/dev/null || true; exit 0' INT TERM

while true; do
    reply="$(fetch "${GATEWAY_URL}?action=next&device=${DEVICE}&token=${TOKEN}" || true)"

    if [ -z "$reply" ]; then
        log "No answer from the server. Still offline, or the address is wrong."
        sleep "$POLL_SECONDS"
        continue
    fi

    case "$reply" in
        NONE)
            # Nothing waiting. This is the normal, quiet case.
            sleep "$POLL_SECONDS"
            continue
            ;;
        CLOSED)
            # The shop is shut, so no orders are coming. Check back rarely
            # rather than every few seconds: over a night that is the
            # difference between a few hundred requests and a handful.
            sleep "$CLOSED_POLL_SECONDS"
            continue
            ;;
        *'"ok":false'*|*'Not authorised'*)
            log "The server refused us. Check the token in Settings matches TOKEN above."
            sleep 60
            continue
            ;;
    esac

    # The answer is one line: id|number|message
    # Cut on the first two pipes only, so a pipe inside the message text
    # cannot split it into the wrong pieces.
    id="${reply%%|*}"
    rest="${reply#*|}"
    number="${rest%%|*}"
    message="${rest#*|}"

    if [ -z "$id" ] || [ -z "$number" ] || [ -z "$message" ] || [ "$id" = "$reply" ]; then
        log "Could not read the answer: $reply"
        sleep "$POLL_SECONDS"
        continue
    fi

    log "Sending #$id to $number"

    if termux-sms-send -n "$number" "$message" 2>>"$LOG"; then
        fetch "${GATEWAY_URL}?action=done&id=${id}&ok=1&device=${DEVICE}&token=${TOKEN}" >/dev/null
        log "Sent #$id"
    else
        # Tell the server it failed, so it shows as failed in the admin
        # instead of sitting in the queue looking like it is still coming.
        fetch "${GATEWAY_URL}?action=done&id=${id}&ok=0&device=${DEVICE}&token=${TOKEN}" >/dev/null
        log "Could not send #$id. Check signal and the SMS permission."
    fi

    # A short pause between messages, rather than the full poll interval, so a
    # backlog drains quickly once the phone is back online.
    sleep 2
done
