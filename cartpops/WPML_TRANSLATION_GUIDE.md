# WPML Translation Guide for Free Shipping Text

## Issue Summary
The free shipping meter text strings in CartPops were not appearing in WPML's String Translation interface because WPML needed to be explicitly told which WordPress options should be made available for translation.

## Solution
We've added a `wpml-config.xml` file to the plugin root directory that registers the free shipping meter text options with WPML's String Translation module.

## How to Translate Free Shipping Text with WPML

### Step 1: Update the Plugin
Make sure you have the latest version of CartPops with the `wpml-config.xml` file in the plugin root directory.

### Step 2: Scan for Strings
1. Go to **WPML → Theme and plugins localization** in your WordPress admin
2. Click on the **Scan selected plugins for strings** button
3. Or go to **WPML → String Translation**
4. At the bottom of the page, click on **Translate texts in admin screens »**

### Step 3: Find the Free Shipping Strings
1. Navigate to **WPML → String Translation**
2. In the "Select strings within domain" dropdown, select **admin_texts_cartpops_free_shipping_meter_text**
3. You should now see the following strings available for translation:
   - `cartpops_free_shipping_meter_text_base` - The text displayed before the free shipping goal is achieved
   - `cartpops_free_shipping_meter_text_achieved` - The text displayed after the free shipping goal is achieved

### Step 4: Translate the Strings
1. Select the language you want to translate to
2. Click the **+** icon next to each string
3. Enter your translated text
4. Click **Save** or **Translation is complete**

### Step 5: Verify the Translation
1. Switch your site to the translated language (using the WPML language switcher)
2. Add a product to cart to trigger CartPops
3. Verify that the free shipping meter text appears in the translated language

## What Gets Translated

The WPML configuration allows translation of:

### 1. Free Shipping Meter Base Text
**Option Key:** `cartpops_free_shipping_meter_text_base`
**Default:** "🎁 Only {{amount}} away from free shipping to {{country}} {{flag}}"
**Location:** CartPops → Settings → Free Shipping Meter settings → Free Shipping Meter Text

This is the text that appears when the customer hasn't yet reached the free shipping threshold.

### 2. Free Shipping Meter Achieved Text
**Option Key:** `cartpops_free_shipping_meter_text_achieved`
**Default:** "Congrats 🎉! You have earned free shipping to {{country}} {{flag}}"
**Location:** CartPops → Settings → Free Shipping Meter settings → Free Shipping Meter Achieved Text

This is the text that appears when the customer has reached the free shipping threshold.

## Available Placeholders

When translating these strings, you can use the following dynamic placeholders:

- `{{amount}}` - The remaining amount needed for free shipping (e.g., "$25.00")
- `{{country}}` - The customer's country (requires MaxMind GEO API integration)
- `{{flag}}` - The customer's country flag emoji (requires MaxMind GEO API integration)

**Example translations:**

**Lithuanian (Base Text):**
```
🎁 Dar tik {{amount}} iki nemokamo pristatymo į {{country}} {{flag}}
```

**Lithuanian (Achieved Text):**
```
Sveikiname 🎉! Jūs užsidirbote nemokamą pristatymą į {{country}} {{flag}}
```

## Technical Details

### WPML Configuration File Location
`/wpml-config.xml`

### Configuration Contents
```xml
<wpml-config>
    <admin-texts>
        <key name="cartpops_free_shipping_meter_text_base"/>
        <key name="cartpops_free_shipping_meter_text_achieved"/>
    </admin-texts>
</wpml-config>
```

This configuration tells WPML to:
1. Register the specified WordPress options as translatable admin strings
2. Make them available in the String Translation interface
3. Allow per-language customization of these values

## Troubleshooting

### Strings Not Appearing After Update
1. Go to **WPML → Theme and plugins localization**
2. Click **Scan selected plugins for strings**
3. Go to **WPML → String Translation** and look for the strings

### Translation Not Showing on Frontend
1. Clear all caches (WordPress cache, browser cache, CDN cache)
2. Make sure you've marked the translation as "Translation is complete"
3. Verify that WPML's language switcher is working correctly
4. Check that the correct language is active on the frontend

### Still Can't Find the Strings
1. Make sure the `wpml-config.xml` file exists in the CartPops plugin root directory
2. Verify that WPML String Translation is activated
3. Try deactivating and reactivating the CartPops plugin
4. Look under **WPML → String Translation** → Domain filter for "admin_texts_cartpops"

## Support

If you continue to experience issues with WPML translation:
1. Ensure you're using the latest version of CartPops
2. Ensure you're using a compatible version of WPML
3. Contact CartPops support at help@cartpops.com
4. Provide details about your WPML version and the specific strings that aren't translating

## Additional Resources

- [WPML String Translation Documentation](https://wpml.org/documentation/getting-started-guide/string-translation/)
- [WPML Configuration File Reference](https://wpml.org/documentation/support/language-configuration-files/)
- [CartPops Documentation](https://cartpops.com/docs)
