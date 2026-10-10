<x-field name="code" label="Code" required maxlength="12" placeholder="HOT" hint="Short unique code"/>
<x-field name="name" label="Name" required placeholder="Hot kitchen"/>
<x-field name="outlet_id" label="Outlet" type="select" :options="$outlets" placeholder="All outlets"/>
<x-field name="role" label="Role" type="select" :options="\App\Services\KotPrintService::ROLES" :value="old('role', 'station')" :placeholder="false"/>
<x-field name="station" label="Kitchen station" placeholder="Kitchen, Bar, Tandoor…" hint="Must match the category station. Kitchen is the default."/>
<x-field name="connection" label="Connection" type="select" :options="\App\Services\KotPrintService::CONNECTIONS" :value="old('connection', 'browser')" :placeholder="false"/>
<x-field name="host" label="IP address" placeholder="192.168.50.21" hint="Private LAN only. Ignored for browser printers."/>
<x-field name="port" label="Port" type="number" :value="old('port', 9100)" min="1" max="65535"/>
<x-field name="paper" label="Paper" type="select" :options="\App\Services\KotPrintService::PAPERS" :value="old('paper', '80')" :placeholder="false"/>
<x-field name="copies" label="Copies" type="number" :value="old('copies', 1)" min="1" max="5"/>
<x-field name="header" label="Header on the slip" placeholder="HOT KITCHEN — FIRE NOW"/>
<x-field name="sort_order" label="Sort" type="number" :value="old('sort_order', 0)" min="0" max="999"/>
<x-field name="notes" label="Notes" class="span-2"/>
<label class="check"><input type="checkbox" name="beep" value="1" @checked(old('beep', true))> Beep when a network ticket arrives</label>
<label class="check"><input type="checkbox" name="is_fallback" value="1" @checked(old('is_fallback'))> Fallback — items with no station printer</label>
<label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Active</label>
