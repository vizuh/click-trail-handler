"""
Build ClickTrail GTM Starter Kit from Stape reference template.

Takes the proven-importable Stape fb-ga4-gads-web.json as structural base,
strips Shopify-specific content, adds ClickTrail attribution + engagement.

Run: py -3 assets/build-starter-kit.py
"""
import json
import time
import copy
import os

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STAPE_FILE = os.path.join(SCRIPT_DIR, "shopify-gtm-container-templates-master", "fb-ga4-gads-web.json")
OUTPUT_FILE = os.path.join(SCRIPT_DIR, "gtm-starter-kit.json")

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def fp():
    """Generate a fingerprint (timestamp string)."""
    return str(int(time.time() * 1000))

def make_dlv(vid, name, dl_path, folder_id, notes=""):
    """Create a Data Layer Variable."""
    v = {
        "accountId": AID, "containerId": CID,
        "variableId": str(vid), "name": name, "type": "v",
        "parameter": [
            {"type": "INTEGER", "key": "dataLayerVersion", "value": "2"},
            {"type": "BOOLEAN", "key": "setDefaultValue", "value": "false"},
            {"type": "TEMPLATE", "key": "name", "value": dl_path}
        ],
        "fingerprint": fp(), "parentFolderId": str(folder_id)
    }
    if notes:
        v["notes"] = notes
    return v

def make_const(vid, name, placeholder, folder_id, notes=""):
    """Create a Constant variable."""
    v = {
        "accountId": AID, "containerId": CID,
        "variableId": str(vid), "name": name, "type": "c",
        "parameter": [{"type": "TEMPLATE", "key": "value", "value": placeholder}],
        "fingerprint": fp(), "parentFolderId": str(folder_id)
    }
    if notes:
        v["notes"] = notes
    return v

def make_ce_trigger(tid, name, event_name, folder_id):
    """Create a Custom Event trigger."""
    return {
        "accountId": AID, "containerId": CID,
        "triggerId": str(tid), "name": name, "type": "CUSTOM_EVENT",
        "customEventFilter": [{
            "type": "EQUALS",
            "parameter": [
                {"type": "TEMPLATE", "key": "arg0", "value": "{{_event}}"},
                {"type": "TEMPLATE", "key": "arg1", "value": event_name}
            ]
        }],
        "fingerprint": fp(), "parentFolderId": str(folder_id)
    }

def make_ga4_event(tag_id, name, event_name, trigger_id, folder_id, extra_params=None):
    """Create a GA4 Event tag (gaawe)."""
    params = [
        {"type": "BOOLEAN", "key": "sendEcommerceData", "value": "false"},
        {"type": "BOOLEAN", "key": "enhancedUserId", "value": "false"},
        {"type": "TEMPLATE", "key": "eventName", "value": event_name},
        {"type": "TEMPLATE", "key": "measurementIdOverride",
         "value": "{{CONST - GA4 Measurement ID}}"},
    ]
    if extra_params:
        params.append({
            "type": "LIST", "key": "eventSettingsTable",
            "list": [
                {"type": "MAP", "map": [
                    {"type": "TEMPLATE", "key": "parameter", "value": k},
                    {"type": "TEMPLATE", "key": "parameterValue", "value": v}
                ]} for k, v in extra_params.items()
            ]
        })
    return {
        "accountId": AID, "containerId": CID,
        "tagId": str(tag_id), "name": name, "type": "gaawe",
        "parameter": params,
        "fingerprint": fp(),
        "firingTriggerId": [str(trigger_id)],
        "parentFolderId": str(folder_id),
        "tagFiringOption": "ONCE_PER_EVENT",
        "monitoringMetadata": {"type": "MAP"},
        "consentSettings": {"consentStatus": "NOT_SET"}
    }

def make_fb_tag(tag_id, name, std_event, trigger_id, folder_id, extra_params=None):
    """Create a Facebook Pixel tag using official template."""
    params = [
        {"type": "BOOLEAN", "key": "disablePushState", "value": "true"},
        {"type": "TEMPLATE", "key": "pixelId", "value": "{{CONST - Meta Pixel ID}}"},
        {"type": "TEMPLATE", "key": "standardEventName", "value": std_event},
        {"type": "BOOLEAN", "key": "disableAutoConfig", "value": "false"},
        {"type": "BOOLEAN", "key": "enhancedEcommerce", "value": "false"},
        {"type": "BOOLEAN", "key": "dpoLDU", "value": "false"},
        {"type": "TEMPLATE", "key": "eventName", "value": "standard"},
        {"type": "BOOLEAN", "key": "objectPropertiesFromVariable", "value": "false"},
        {"type": "BOOLEAN", "key": "consent", "value": "true"},
        {"type": "BOOLEAN", "key": "advancedMatching", "value": "false"},
    ]
    if extra_params:
        for k, v in extra_params.items():
            params.append({"type": "TEMPLATE", "key": k, "value": v})
    return {
        "accountId": AID, "containerId": CID,
        "tagId": str(tag_id), "name": name, "type": FB_TAG_TYPE,
        "parameter": params,
        "fingerprint": fp(),
        "firingTriggerId": [str(trigger_id)],
        "parentFolderId": str(folder_id),
        "tagFiringOption": "ONCE_PER_EVENT",
        "monitoringMetadata": {"type": "MAP"},
        "consentSettings": {"consentStatus": "NOT_SET"}
    }


# ---------------------------------------------------------------------------
# Load Stape reference
# ---------------------------------------------------------------------------

with open(STAPE_FILE, encoding="utf-8") as f:
    stape = json.load(f)

sc = stape["containerVersion"]
AID = sc["accountId"]
CID = sc["containerId"]
FB_TAG_TYPE = f"cvt_{CID}_21"  # Official Facebook Pixel template ID in this container

# ---------------------------------------------------------------------------
# Extract the Facebook Pixel customTemplate (keep only this one)
# ---------------------------------------------------------------------------

fb_template = None
for ct in sc.get("customTemplate", []):
    if "facebook" in ct["name"].lower() or "pixel" in ct["name"].lower():
        fb_template = copy.deepcopy(ct)
        break

assert fb_template, "Facebook Pixel template not found in Stape reference"

# ---------------------------------------------------------------------------
# Define folder structure
# ---------------------------------------------------------------------------

FOLDER_SETUP = "200"
FOLDER_ATTR  = "201"
FOLDER_GA4   = "202"
FOLDER_META  = "203"
FOLDER_ADS   = "204"
FOLDER_TRIGS = "205"
FOLDER_DL    = "206"

folders = [
    {"folderId": FOLDER_SETUP, "accountId": AID, "containerId": CID,
     "name": "ClickTrail - Setup (fill these in)", "fingerprint": fp()},
    {"folderId": FOLDER_ATTR, "accountId": AID, "containerId": CID,
     "name": "ClickTrail - Attribution Variables", "fingerprint": fp()},
    {"folderId": FOLDER_GA4, "accountId": AID, "containerId": CID,
     "name": "ClickTrail - GA4", "fingerprint": fp()},
    {"folderId": FOLDER_META, "accountId": AID, "containerId": CID,
     "name": "ClickTrail - Meta", "fingerprint": fp()},
    {"folderId": FOLDER_ADS, "accountId": AID, "containerId": CID,
     "name": "ClickTrail - Google Ads", "fingerprint": fp()},
    {"folderId": FOLDER_TRIGS, "accountId": AID, "containerId": CID,
     "name": "ClickTrail - Triggers", "fingerprint": fp()},
    {"folderId": FOLDER_DL, "accountId": AID, "containerId": CID,
     "name": "ClickTrail - Data Layer", "fingerprint": fp()},
]

# ---------------------------------------------------------------------------
# Variables
# ---------------------------------------------------------------------------

vid = 200  # start high to avoid collisions

# Setup constants
variables = [
    make_const(vid := vid + 1, "CONST - GA4 Measurement ID", "G-XXXXXXXXXX", FOLDER_SETUP,
               "Replace with your GA4 Measurement ID."),
    make_const(vid := vid + 1, "CONST - Ads Conversion ID", "XXXXXXXXX", FOLDER_SETUP,
               "NUMERIC conversion ID only — do NOT include the 'AW-' prefix. The Conversion Tracking tag (with a conversion label) only accepts the bare digits; the AW- form silently fails there. These are the digits in 'AW-XXXXXXXXX/<label>' before the slash. Used by the Google Ads conversion tags. Leave blank to disable."),
    make_const(vid := vid + 1, "CONST - Ads Conversion ID (AW-)", "AW-XXXXXXXXX", FOLDER_SETUP,
               "Full 'AW-XXXXXXXXX' form. Used ONLY by 'Google Ads - Config' (the Google tag / gtag loader), which accepts the AW- prefix. Leave blank to disable the Google Ads global tag."),
    make_const(vid := vid + 1, "CONST - Ads Purchase Label", "AbCdEfGhIjK", FOLDER_SETUP,
               "Your Google Ads purchase conversion label. Found in Google Ads > Goals > Conversions."),
    make_const(vid := vid + 1, "CONST - Ads Thank You Label", "AbCdEfGhIjK", FOLDER_SETUP,
               "Your Google Ads lead conversion label (thank-you page). Found in Google Ads > Goals > Conversions. Leave the default to keep the tag inactive until you set it."),
    make_const(vid := vid + 1, "CONST - Thank You Page Path", "/thank-you", FOLDER_SETUP,
               "Path (or partial path) of the thank-you / success page used by your forms or booking flow. The Thank You trigger fires when Page Path 'contains' this string. Examples: /thank-you, /obrigado, /success, /contact/success."),
    make_const(vid := vid + 1, "CONST - Meta Pixel ID", "0000000000000", FOLDER_SETUP,
               "Your Meta Pixel ID (numbers only). Leave blank to disable Meta tags."),
    make_const(vid := vid + 1, "CONST - sGTM Endpoint", "https://gtm.yourdomain.com", FOLDER_SETUP,
               "Your server-side GTM URL. Leave blank if not using sGTM."),
]

# Ecommerce data layer variables
ecom_vars = {
    "ecommerce.transaction_id": "DLV - transaction_id",
    "ecommerce.value": "DLV - value",
    "ecommerce.currency": "DLV - currency",
    "ecommerce.items": "DLV - items",
    "ecommerce.coupon": "DLV - coupon",
    "ecommerce.shipping": "DLV - shipping",
    "ecommerce.tax": "DLV - tax",
}
for dl_path, name in ecom_vars.items():
    variables.append(make_dlv(vid := vid + 1, name, dl_path, FOLDER_DL))

# Attribution variables (ClickTrail-specific)
attr_vars = [
    ("ft_source", "DLV - ft_source"),
    ("lt_source", "DLV - lt_source"),
    ("ft_medium", "DLV - ft_medium"),
    ("lt_medium", "DLV - lt_medium"),
    ("ft_campaign", "DLV - ft_campaign"),
    ("lt_campaign", "DLV - lt_campaign"),
    ("ft_channel", "DLV - ft_channel"),
    ("lt_channel", "DLV - lt_channel"),
    ("gclid", "DLV - gclid"),
    ("fbclid", "DLV - fbclid"),
    ("ga_client_id", "DLV - ga_client_id"),
    ("event_id", "DLV - event_id"),
]
for dl_path, name in attr_vars:
    variables.append(make_dlv(vid := vid + 1, name, dl_path, FOLDER_ATTR))

# ---------------------------------------------------------------------------
# Triggers
# ---------------------------------------------------------------------------

tid = 200

# Page-level
tid += 1; TRIG_ALL_PAGES = tid
tid += 1; TRIG_DOM_READY = tid
tid += 1; TRIG_THANKYOU  = tid

triggers = [
    {"accountId": AID, "containerId": CID, "triggerId": str(TRIG_ALL_PAGES),
     "name": "All Pages", "type": "PAGEVIEW", "fingerprint": fp(),
     "parentFolderId": FOLDER_TRIGS},
    {"accountId": AID, "containerId": CID, "triggerId": str(TRIG_DOM_READY),
     "name": "DOM Ready", "type": "DOM_READY", "fingerprint": fp(),
     "parentFolderId": FOLDER_TRIGS},
    {"accountId": AID, "containerId": CID, "triggerId": str(TRIG_THANKYOU),
     "name": "Page View - Thank You Page", "type": "PAGEVIEW",
     "filter": [{
         "type": "CONTAINS",
         "parameter": [
             {"type": "TEMPLATE", "key": "arg0", "value": "{{Page Path}}"},
             {"type": "TEMPLATE", "key": "arg1", "value": "{{CONST - Thank You Page Path}}"}
         ]
     }],
     "fingerprint": fp(), "parentFolderId": FOLDER_TRIGS},
]

# Ecommerce events (standard GA4 names — ClickTrail pushes these)
ecom_events = {}
for evt in ["purchase", "add_to_cart", "begin_checkout", "view_item", "view_item_list"]:
    tid += 1; ecom_events[evt] = tid
for evt, t_id in ecom_events.items():
    triggers.append(make_ce_trigger(t_id, f"CE - {evt}", evt, FOLDER_TRIGS))

# Engagement events (ClickTrail-specific)
engage_events = {}
for evt in ["form_submit", "email_click", "phone_click", "whatsapp_click"]:
    tid += 1; engage_events[evt] = tid
for evt, t_id in engage_events.items():
    triggers.append(make_ce_trigger(t_id, f"LC - {evt}", evt, FOLDER_TRIGS))

# Store trigger IDs for tags
TRIG = {**ecom_events, **engage_events}

# ---------------------------------------------------------------------------
# Tags
# ---------------------------------------------------------------------------

tag_id = 200
tags = []

# --- GA4 Config ---
tags.append({
    "accountId": AID, "containerId": CID,
    "tagId": str(tag_id := tag_id + 1), "name": "GA4 - Config", "type": "googtag",
    "parameter": [
        {"type": "TEMPLATE", "key": "tagId", "value": "{{CONST - GA4 Measurement ID}}"},
        {"type": "LIST", "key": "configSettingsTable", "list": [
            {"type": "MAP", "map": [
                {"type": "TEMPLATE", "key": "parameter", "value": "server_container_url"},
                {"type": "TEMPLATE", "key": "parameterValue", "value": "{{CONST - sGTM Endpoint}}"}
            ]},
        ]},
    ],
    "fingerprint": fp(),
    "firingTriggerId": [str(TRIG_ALL_PAGES)],
    "priority": {"type": "INTEGER", "value": "20"},
    "parentFolderId": FOLDER_GA4,
    "tagFiringOption": "ONCE_PER_EVENT",
    "monitoringMetadata": {"type": "MAP"},
    "consentSettings": {"consentStatus": "NOT_SET"},
    "notes": "Initializes GA4 via the Google tag. All GA4 event tags use measurementIdOverride."
})

# --- Google Ads Config ---
tags.append({
    "accountId": AID, "containerId": CID,
    "tagId": str(tag_id := tag_id + 1), "name": "Google Ads - Config", "type": "googtag",
    "parameter": [
        {"type": "TEMPLATE", "key": "tagId", "value": "{{CONST - Ads Conversion ID (AW-)}}"},
    ],
    "fingerprint": fp(),
    "firingTriggerId": [str(TRIG_ALL_PAGES)],
    "priority": {"type": "INTEGER", "value": "15"},
    "parentFolderId": FOLDER_ADS,
    "tagFiringOption": "ONCE_PER_EVENT",
    "monitoringMetadata": {"type": "MAP"},
    "consentSettings": {"consentStatus": "NOT_SET"},
})

# --- Google Ads Conversion Linker ---
tags.append({
    "accountId": AID, "containerId": CID,
    "tagId": str(tag_id := tag_id + 1), "name": "Google Ads - Conversion Linker", "type": "gclidw",
    "parameter": [
        {"type": "BOOLEAN", "key": "enableCrossDomain", "value": "false"},
    ],
    "fingerprint": fp(),
    "firingTriggerId": [str(TRIG_ALL_PAGES)],
    "parentFolderId": FOLDER_ADS,
    "tagFiringOption": "ONCE_PER_EVENT",
    "monitoringMetadata": {"type": "MAP"},
    "consentSettings": {"consentStatus": "NOT_SET"},
    "setupTag": [{"tagName": "Google Ads - Config", "stopOnSetupFailure": False}],
})

# --- Google Ads Purchase Conversion ---
tags.append({
    "accountId": AID, "containerId": CID,
    "tagId": str(tag_id := tag_id + 1), "name": "Google Ads - Purchase Conversion", "type": "awct",
    "parameter": [
        {"type": "TEMPLATE", "key": "conversionId", "value": "{{CONST - Ads Conversion ID}}"},
        {"type": "TEMPLATE", "key": "conversionLabel", "value": "{{CONST - Ads Purchase Label}}"},
        {"type": "TEMPLATE", "key": "conversionValue", "value": "{{DLV - value}}"},
        {"type": "TEMPLATE", "key": "currencyCode", "value": "{{DLV - currency}}"},
        {"type": "TEMPLATE", "key": "orderId", "value": "{{DLV - transaction_id}}"},
        {"type": "BOOLEAN", "key": "enableNewCustomerReporting", "value": "false"},
        {"type": "BOOLEAN", "key": "enableConversionLinker", "value": "true"},
        {"type": "BOOLEAN", "key": "enableProductReporting", "value": "false"},
        {"type": "BOOLEAN", "key": "enableShippingData", "value": "false"},
        {"type": "BOOLEAN", "key": "rdp", "value": "false"},
    ],
    "fingerprint": fp(),
    "firingTriggerId": [str(TRIG["purchase"])],
    "parentFolderId": FOLDER_ADS,
    "tagFiringOption": "ONCE_PER_EVENT",
    "monitoringMetadata": {"type": "MAP"},
    "consentSettings": {"consentStatus": "NOT_SET"},
    "setupTag": [{"tagName": "Google Ads - Config", "stopOnSetupFailure": False}],
})

# --- Google Ads Thank You Page Conversion (lead-gen / booking flows) ---
tags.append({
    "accountId": AID, "containerId": CID,
    "tagId": str(tag_id := tag_id + 1), "name": "Google Ads - Thank You Conversion", "type": "awct",
    "parameter": [
        {"type": "TEMPLATE", "key": "conversionId", "value": "{{CONST - Ads Conversion ID}}"},
        {"type": "TEMPLATE", "key": "conversionLabel", "value": "{{CONST - Ads Thank You Label}}"},
        {"type": "BOOLEAN", "key": "enableNewCustomerReporting", "value": "false"},
        {"type": "BOOLEAN", "key": "enableConversionLinker", "value": "true"},
        {"type": "BOOLEAN", "key": "enableProductReporting", "value": "false"},
        {"type": "BOOLEAN", "key": "enableShippingData", "value": "false"},
        {"type": "BOOLEAN", "key": "rdp", "value": "false"},
    ],
    "fingerprint": fp(),
    "firingTriggerId": [str(TRIG_THANKYOU)],
    "parentFolderId": FOLDER_ADS,
    "tagFiringOption": "ONCE_PER_EVENT",
    "monitoringMetadata": {"type": "MAP"},
    "consentSettings": {"consentStatus": "NOT_SET"},
    "setupTag": [{"tagName": "Google Ads - Config", "stopOnSetupFailure": False}],
    "notes": (
        "Fires when the user reaches a page whose path contains "
        "{{CONST - Thank You Page Path}}. Use this when your form submit or booking "
        "completion redirects to a thank-you page and that page IS the main Google Ads "
        "conversion (lead-gen pattern). Set CONST - Ads Thank You Label and "
        "CONST - Thank You Page Path before publishing."
    )
})

# --- GA4 Ecommerce Events ---
ga4_ecom = {
    "purchase": {"currency": "{{DLV - currency}}", "value": "{{DLV - value}}",
                 "transaction_id": "{{DLV - transaction_id}}", "coupon": "{{DLV - coupon}}",
                 "shipping": "{{DLV - shipping}}", "tax": "{{DLV - tax}}",
                 "items": "{{DLV - items}}"},
    "add_to_cart": {"currency": "{{DLV - currency}}", "value": "{{DLV - value}}",
                    "items": "{{DLV - items}}"},
    "begin_checkout": {"currency": "{{DLV - currency}}", "value": "{{DLV - value}}",
                       "items": "{{DLV - items}}"},
    "view_item": {"currency": "{{DLV - currency}}", "value": "{{DLV - value}}",
                  "items": "{{DLV - items}}"},
    "view_item_list": {"items": "{{DLV - items}}"},
}
for evt, params in ga4_ecom.items():
    tags.append(make_ga4_event(
        tag_id := tag_id + 1,
        f"GA4 Event - {evt}", evt, TRIG[evt], FOLDER_GA4, params
    ))

# --- GA4 Engagement Events ---
for evt in ["form_submit", "email_click", "phone_click", "whatsapp_click"]:
    tags.append(make_ga4_event(
        tag_id := tag_id + 1,
        f"GA4 Event - {evt}", evt, TRIG[evt], FOLDER_GA4
    ))

# --- Meta Pixel Tags ---
tags.append(make_fb_tag(
    tag_id := tag_id + 1, "Meta Pixel - PageView", "PageView",
    TRIG_DOM_READY, FOLDER_META
))
tags.append(make_fb_tag(
    tag_id := tag_id + 1, "Meta Pixel - Purchase", "Purchase",
    TRIG["purchase"], FOLDER_META
))
tags.append(make_fb_tag(
    tag_id := tag_id + 1, "Meta Pixel - Lead", "Lead",
    TRIG["form_submit"], FOLDER_META
))
tags.append(make_fb_tag(
    tag_id := tag_id + 1, "Meta Pixel - AddToCart", "AddToCart",
    TRIG["add_to_cart"], FOLDER_META
))
tags.append(make_fb_tag(
    tag_id := tag_id + 1, "Meta Pixel - InitiateCheckout", "InitiateCheckout",
    TRIG["begin_checkout"], FOLDER_META
))
tags.append(make_fb_tag(
    tag_id := tag_id + 1, "Meta Pixel - ViewContent", "ViewContent",
    TRIG["view_item"], FOLDER_META
))

# ---------------------------------------------------------------------------
# Built-in variables (same as Stape — good coverage)
# ---------------------------------------------------------------------------

builtin_types = [
    "PAGE_URL", "PAGE_HOSTNAME", "PAGE_PATH", "REFERRER", "EVENT",
    "CLICK_ELEMENT", "CLICK_CLASSES", "CLICK_ID", "CLICK_TARGET",
    "CLICK_URL", "CLICK_TEXT",
    "FORM_ELEMENT", "FORM_CLASSES", "FORM_ID", "FORM_TARGET",
    "FORM_URL", "FORM_TEXT",
]
builtins = [
    {"accountId": AID, "containerId": CID,
     "type": t, "name": t.replace("_", " ").title()}
    for t in builtin_types
]

# ---------------------------------------------------------------------------
# Assemble container
# ---------------------------------------------------------------------------

output = {
    "exportFormatVersion": 2,
    "exportTime": "2026-05-08 00:00:00",
    "containerVersion": {
        "path": f"accounts/{AID}/containers/{CID}/versions/0",
        "accountId": AID,
        "containerId": CID,
        "containerVersionId": "0",
        "fingerprint": fp(),
        "container": {
            "path": f"accounts/{AID}/containers/{CID}",
            "accountId": AID,
            "containerId": CID,
            "name": "ClickTrail Starter Kit",
            "publicId": "GTM-XXXXXXX",
            "usageContext": ["WEB"],
            "fingerprint": fp(),
            "features": {
                "supportUserPermissions": True,
                "supportEnvironments": True,
                "supportWorkspaces": True,
                "supportGtagConfigs": False,
                "supportBuiltInVariables": True,
                "supportClients": False,
                "supportFolders": True,
                "supportTags": True,
                "supportTemplates": True,
                "supportTriggers": True,
                "supportVariables": True,
                "supportVersions": True,
                "supportZones": True,
                "supportTransformations": False
            },
        },
        "builtInVariable": builtins,
        "customTemplate": [fb_template],
        "folder": folders,
        "variable": variables,
        "trigger": triggers,
        "tag": tags,
    }
}

with open(OUTPUT_FILE, "w", encoding="utf-8", newline="\n") as f:
    json.dump(output, f, indent=2, ensure_ascii=False)

# Verify
cv = output["containerVersion"]
print(f"Written: {OUTPUT_FILE}")
print(f"  Tags:       {len(cv['tag'])}")
print(f"  Triggers:   {len(cv['trigger'])}")
print(f"  Variables:  {len(cv['variable'])}")
print(f"  Folders:    {len(cv['folder'])}")
print(f"  Templates:  {len(cv['customTemplate'])}")
print(f"  BuiltInVar: {len(cv['builtInVariable'])}")
print(f"  FB type:    {FB_TAG_TYPE}")
