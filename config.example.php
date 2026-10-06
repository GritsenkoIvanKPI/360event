<?php
/**
 * Copy this file to config.php on the server and fill in the real values.
 *
 * config.php is git-ignored on purpose — the bot token must never be committed or
 * served to the browser. Anyone holding it controls the bot.
 */

return [
    // From @BotFather. Keep it secret. Create the file straight on the server.
    'bot_token' => 'PASTE_BOT_TOKEN_HERE',

    // Where leads are delivered. A group id is negative (-100…), a private chat id
    // is positive. Harmless on its own: without the token nobody can post to it.
    //
    // To find it: add the bot to the group, send any message there, then open
    // https://api.telegram.org/bot<TOKEN>/getUpdates and read "chat":{"id":…}.
    'chat_id' => 'PASTE_CHAT_ID_HERE',

    // Optional: mirror every lead to this mailbox as well. Empty = off.
    'notify_email' => '',

    // Max submissions accepted from one IP per hour.
    'rate_limit_per_hour' => 5,
];
