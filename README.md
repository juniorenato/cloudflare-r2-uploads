# cloudflare-r2-uploads
A WordPress plugin that uploads all media files to Cloudflare R2.

## Installation
1. Copy `cloudflare-r2-uploads.php` to your WordPress plugins directory.
2. Activate **Cloudflare R2 Uploads** in WordPress.
3. Go to **Settings → Cloudflare R2 Uploads** and configure:
   - Account ID
   - Access Key ID
   - Secret Access Key
   - Bucket Name
   - Public Base URL (recommended: custom domain for your R2 bucket)
   - Optional object prefix

After configuration, new media uploads (including generated image sizes) are uploaded to Cloudflare R2.
