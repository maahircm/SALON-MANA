<?php

namespace App\Http\Controllers;

use App\Models\AppointmentService;
use App\Models\Room;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        $this->authorize('employees.schedule');

        return view('appointments.rooms', ['rooms' => Room::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $this->authorize('employees.schedule');
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:rooms,name'], 'type' => ['required', Rule::in(array_keys(Room::TYPES))]]);
        $room = Room::create($data + ['is_active' => true]);
        $this->audit->log('room.created', $room, null, $data);

        return back()->with('success', 'Chair / room added.');
    }

    public function update(Request $request, Room $room)
    {
        $this->authorize('employees.schedule');
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('rooms', 'name')->ignore($room->id)], 'type' => ['required', Rule::in(array_keys(Room::TYPES))]]);
        $room->fill($data)->forceFill(['is_active' => $request->boolean('is_active')])->save();
        $this->audit->log('room.updated', $room, null, $data + ['is_active' => $room->is_active]);

        return back()->with('success', 'Updated.');
    }

    public function destroy(Room $room)
    {
        $this->authorize('employees.schedule');
        if (AppointmentService::where('room_id', $room->id)->exists()) {
            throw ValidationException::withMessages(['room' => 'This chair/room has booking history. Mark it inactive instead.']);
        }
        $room->delete();
        $this->audit->log('room.deleted', $room);

        return back()->with('success', 'Deleted.');
    }
}
