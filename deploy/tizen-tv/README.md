# Nowills PMS IPTV for Samsung Tizen TVs

This is a Samsung Tizen **TV web-app project** that installs as a TV application and opens the existing Nowills PMS IPTV portal. It is not a native channel tuner and does not replace Samsung Hotel Mode or a hospitality management platform.

The app starts at a small on-TV connection screen. Enter the PMS HTTPS address and hotel code once. It then opens the existing `/tv/setup/{hotel-code}` pairing flow; the TV displays its pairing code, and staff approves it in **IPTV → TVs & Screens → Pair a TV**. After pairing, the app opens that TV's token-specific portal. The connection details are remembered by the installed app, so subsequent launches return to the pairing check and then the portal. A short, focused startup prompt allows staff to press OK to change the server or hotel before the automatic launch continues.

When launched with `launch=welcome`, the portal shows the in-house guest welcome screen on each app launch (while the hotel's welcome feature is enabled). It also returns to that screen when the TV app resumes from a hidden/background state. That gives a returning guest the welcome screen after TV power-on as well as on the first launch of their stay.

## Build a signed Samsung TV package

A Tizen `.wgt` must be signed with a Samsung/Tizen certificate profile accepted by the target TVs. This repository does not contain a signing key or a pre-signed package. Do not commit or send signing private keys.

1. Install Samsung **Tizen Studio**, the **TV Extension**, and the Web App development tools for a TV generation supported by your Samsung hospitality TVs.
2. In Tizen Certificate Manager, create/select a certificate profile for TV applications. Register the target TVs' DUIDs if the profile or installation method requires them. Keep the author and distributor private keys secure.
3. From this directory, run:

   ```sh
   TIZEN_CERT_PROFILE="Your-Samsung-TV-profile" ./build.sh
   ```

4. The signed package is written to `dist/NowillsPmsTV.wgt`. Install and test it on a target Samsung TV using Tizen Studio Device Manager / SDB or deploy it through the hotel's supported Samsung device-management service. Installation commands and certificate requirements vary by model and firmware; follow the Tizen Studio instructions for the TV Extension installed above.

The app requires an HTTPS-reachable Nowills PMS server. The default is `https://pms.nowills.com`; the on-TV form permits changing this for another valid PMS host. Hotel codes are found in Nowills PMS IPTV settings.

## Pair a TV

1. Launch **Nowills PMS IPTV** on the TV.
2. Enter the PMS HTTPS address and hotel code. On later launches the app reuses these settings and automatically returns to the TV portal; use **Change server or hotel** on the short startup screen if the connection needs changing.
3. Wait for the pairing code to appear.
4. In Nowills PMS, open **IPTV → TVs & Screens → Pair a TV**, enter the code, and assign the correct hotel room/device.
5. The TV detects approval and opens its welcome screen. Check in a test guest and verify the name, room, welcome content, and guest QR before handing the TV to a guest.

If the app's saved connection needs to be cleared, use **Change server or hotel** at launch. If the app cannot be opened to reach that control, clear the app's local data or uninstall/reinstall it, then pair again.

## Automatically launch after TV power-on

A Tizen app cannot universally force itself to launch at boot. **Automatic startup is a Samsung hospitality-TV setting**, not something the web app can enable on every model. Once the package is installed and paired:

1. In the TV's Hotel/Pro:Idiom/Hospitality Mode configuration (or the hotel's Samsung management console), select Nowills PMS IPTV as the **Power On Application / Startup Application** if that option is available on that model.
2. If the model exposes an **Application URL Launcher** or managed browser startup URL instead, configure it to launch the Nowills PMS IPTV app or the assigned PMS TV URL, according to the Samsung model's instructions.
3. Disable guest access to installation, pairing, and hotel configuration menus as appropriate for the property.
4. Test a full AC power cycle and standby wake on each TV model/firmware. Confirm it starts the app, reaches the network, checks the pairing, and shows the welcome screen. Some models resume their previous input or channel instead; those models need Samsung-supported hospitality provisioning or another managed launcher to meet an automatic-start requirement.

Menu names and support for startup apps vary by Samsung hospitality model, firmware, region, and management solution. Do not assume that installing the `.wgt` alone configures power-on behavior. Validate the precise model with the hotel's Samsung integrator before bulk deployment.

## Implementation notes

- `config.xml` declares a landscape Tizen TV web app and internet access. `www/launcher.js` is ES5-compatible for older TV browsers and only accepts HTTPS server addresses.
- The launcher itself stores only the server address and hotel code. The paired TV token continues to be managed by the existing Nowills PMS pairing page on the PMS origin.
- The `launch=welcome` URL flag is passed through the pairing redirect and affects only the initial app response. The hotel's TV welcome setting still controls whether guest welcomes are enabled.
- The app uses the existing stateless `/tv/*` API and guest-specific QR/welcome logic; no PMS credentials are embedded in the package.
- Run `node deploy/dev/audit/tizen_tv_launcher_check.js` from the repository root to smoke-test setup, HTTPS validation, saved connection details, and the welcome-start URL.
