<?php

namespace App\Http\Controllers;

use App\Services\NotificationCenter;

class NotificationController extends Controller
{
    /**
     * v7.55 – "Notifications": everything waiting for this user, grouped by the
     * page that clears it. Derived from live data, so there is no read/unread
     * state to keep: deal with the guest and the row goes away by itself.
     */
    public function index()
    {
        $groups = NotificationCenter::forUser();

        return view('notifications.index', [
            'groups' => $groups,
            'total' => NotificationCenter::total($groups),
        ]);
    }
}
