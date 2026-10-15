<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The signed-in user's own in-app notifications. */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', ['notifications' => UserNotification::where('user_id', $request->user()->id)->latest('id')->paginate(20)]);
    }

    /** Marks one notification read and follows its link. Users can only touch their own. */
    public function read(Request $request, UserNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->read_at ??= now();
        $notification->save();

        return $notification->url && str_starts_with($notification->url, url('/')) ? redirect($notification->url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
