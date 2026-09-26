<?php

namespace App\Fleet;

use App\Models\Site;

/**
 * Which host renders a site's pages: another host than the site's own, so a
 * page cannot hide from the one address it can learn (its host's), and the
 * same one each time for a site. The site's own host only when it is alone.
 */
class RenderHost
{
    public static function for(Site $site): string
    {
        $others = array_values(array_diff(array_keys(config('fleet.hosts', [])), [$site->host]));

        return $others ? $others[crc32($site->site_id) % count($others)] : $site->host;
    }
}
