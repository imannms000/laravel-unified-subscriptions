## 1. How to get `google-play-service-account.json`

To get the **google-play-service-account.json** file (required for server-side verification of Google Play Billing subscriptions and real-time notifications), follow these **detailed, step-by-step instructions**. This file is a **service account key** from Google Cloud, linked to your Google Play Console for API access (e.g., validating receipts via Android Publisher API).

**Important Notes**:
- You must be the **owner** of the Google Play Developer account.
- It can take **up to 24 hours** for the service account to fully activate (common delay mentioned in docs).
- The JSON key can only be downloaded **once** during creation — store it securely!
- No billing is required for basic use, but enabling APIs may prompt for a billing account.

### Step 1: Go to Google Play Console and Start API Access Setup

1. Log in to **Google Play Console**: https://play.google.com/console
2. Select your app (or any if multiple).
3. In the left menu, go to **Test and release** > **App integrity**.
4. Scroll down to **Link Cloud project**.
5. Click **Create new project** (recommended) or link an existing one.
   - If creating new: Give it a name like "MyApp Play API".
   - Click **Create and continue**.
6. You will be redirected to **Google Cloud Console** (console.cloud.google.com).

### Step 2: Enable the Required API in Google Cloud

1. In Google Cloud Console, make sure your new project is selected (top dropdown).
2. Go to **APIs & Services** > **Library** (left menu).
3. Search for "**Google Play Android Developer API**".
4. Click it and click **Enable**.
5. Search for "**Cloud Pub/Sub API**"".
6. Click it and click **Enable**.

### Step 3: Create a Service Account and Download the JSON Key

1. In Google Cloud Console, go to **IAM & Admin** > **Service accounts** (left menu).
2. Click **+ Create service account**.
3. Fill in:
   - **Service account name**: e.g., "play-api-service-account"
   - **Service account ID**: Auto-filled (or customize)
   - **Description**: Optional, e.g., "For Google Play Billing verification"
4. Click **Create and continue**.
5. **Grant roles** (Step 2 of wizard):
   - You don't need project-level roles here (permissions come from Play Console later).
   - Skip or add "Viewer" if prompted.
   - Click **Continue**.
6. **Grant users access** (Step 3): Skip (no need).
7. Click **Done**.
8. On the service accounts list, find your new account.
9. Click the three dots (Actions) > **Manage keys**.
10. Click **Add key** > **Create new key**.
11. Choose **Key type: JSON**.
12. Click **Create**.
    - The **google-play-service-account.json** file will **automatically download**!
    - **Save it securely** — this is your credential file. You cannot re-download it later (you'd have to create a new key).

### Step 4: Link the Service Account Back to Google Play Console

2. Under **Service accounts**, you should now see your new service account listed.
3. Copy the **service account email** (looks like `your-service-account@your-project.iam.gserviceaccount.com`).
4. Go to **Play Console** > **Users and permissions** (left menu in Play Console).
5. Click **Invite new user**.
6. Paste the service account email.
7. Under **Account permissions**:
   - Check **Admin (all permissions)** or at minimum:
     - Manage orders and subscriptions
     - View app information and download bulk reports
8. Under **App permissions**: Grant access to your app.
9. Click **Invite user** > **Send invite**.

### Step 5: Place the JSON File in Your Laravel Project

1. Move the downloaded JSON file to a secure location, e.g.:
   ```
   storage/app/google-play-service-account.json
   ```
2. Update your config (`config/subscription.php`):
   ```php
   'google' => [
       'package_name' => 'com.yourcompany.yourapp', // Your app's package name
       'service_account' => storage_path('app/google-play-service-account.json'),
   ],
   ```
3. Ensure the file is **not committed** to git — add to `.gitignore`.

### Step 6: Test It

- Wait up to 24 hours (common propagation delay).
- Check logs for errors if validation fails.

You're done! This JSON file allows your Laravel server to securely communicate with Google Play Billing API for receipt validation and webhooks.

## 2. How to setup `Real-Time Developer Notifications (RTDN)`

If you want to receive Real-Time Developer Notifications (RTDN) from Google Play Billing (for events like subscription renewal, cancellation, expiry, grace period, etc.), you must set it up using Google Cloud Pub/Sub.

**Note: Before continuing, make sure you have already followed Step 1 (above).**

### \# Create a Pub/Sub Topic

In `Google Cloud Console` → `Pub/Sub` → `Topics` → Click `Create topic`
- Topic ID: e.g., `play-billing-notifications`
- Full name: `projects/{your-project-id}/topics/play-billing-notifications`
- Check `Add a default subscription`. Ignore others.
- Click `Create`.

### \# Grant Google Play permission to publish

Go to the new topic (eg. `play-billing-notifications`) → `Permissions` → Click `+ Add principal`.
- Principal: `google-play-developer-notifications@system.gserviceaccount.com` (**IMPORTANT!!!**)
- Role: `Pub/Sub Publisher`
- Save.

### \# Create a Push Subscription (to deliver notifications to your server)

Go to `Subscriptions` → `Create subscription`.
- Subscription ID: e.g., `play-billing-push`
- Select your topic: `play-billing-notifications`
- Delivery type: `Push`
- Endpoint URL: Your webhook (e.g., `https://yourdomain.com/subscriptions/webhooks/google`). Check `./routes/webhooks.php` for more details.
- Retry policy: `Exponential backoff (default)`
- `Create`.

### \# Enable RTDN in Google Play Console:

Go to `Play Console` → `Your App` → `Monetize with Play` → `Monetization setup`
- Scroll to Real-time developer notifications
- Check `Enable` real-time developer notifications
- Topic name: Paste the topics you have previously created. eg. `projects/{your-project-id}/topics/play-billing-notifications`
- `Save`
- Click `Send test notification` to verify.

If successful, your webhook will receive a test message.

### !!! Important Notes !!!

- It can take up to 24–48 hours for permissions to propagate fully.
- Test notification should work immediately after permissions are set.
- Pub/Sub has a free tier (first 10 GB/month free) — very cheap even for high volume.
- Your webhook will receive messages in Pub/Sub format (base64-encoded data) — your code already handles this.
- RTDN is highly recommended for production subscriptions to stay in sync reliably.