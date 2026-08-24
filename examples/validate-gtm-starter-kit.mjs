import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const readJson = (relativePath) => JSON.parse(fs.readFileSync(path.join(repoRoot, relativePath), "utf8"));
const kit = readJson("assets/gtm-starter-kit.json");
const fixtures = readJson("examples/gtm-data-layer-events.json");
const variables = kit.containerVersion.variable;
const triggers = kit.containerVersion.trigger;
const tags = kit.containerVersion.tag;

const parameter = (item, key) => item.parameter?.find((entry) => entry.key === key);
const variable = (name) => variables.find((item) => item.name === name);
const trigger = (name) => triggers.find((item) => item.name === name);

const eventIdVariable = variable("DLV - event_id");
const advertisingConsentVariable = variable("DLV - marketing_trail.consent.advertising");
assert(eventIdVariable, "missing event_id data-layer variable");
assert(advertisingConsentVariable, "missing advertising consent data-layer variable");
assert.equal(parameter(eventIdVariable, "name")?.value, "event_id");
assert.equal(parameter(advertisingConsentVariable, "name")?.value, "marketing_trail.consent.advertising");
assert.equal(parameter(advertisingConsentVariable, "setDefaultValue")?.value, "false");

const wordpressPageView = trigger("CE - ct_page_view");
const javascriptPageView = trigger("CE - page_view");
assert(wordpressPageView, "missing WordPress page-view trigger");
assert(javascriptPageView, "missing JavaScript page-view trigger");
assert.equal(parameter(wordpressPageView.customEventFilter?.[0], "arg1")?.value, "ct_page_view");
assert.equal(parameter(javascriptPageView.customEventFilter?.[0], "arg1")?.value, "page_view");

const metaTags = tags.filter((item) => item.name.startsWith("Meta Pixel - "));
assert.equal(metaTags.length, 6, "expected six Meta starter tags");
for (const tag of metaTags) {
  assert.equal(parameter(tag, "eventId")?.value, "{{DLV - event_id}}", `${tag.name} eventId mapping changed`);
  assert.equal(parameter(tag, "consent")?.value, "{{DLV - marketing_trail.consent.advertising}}", `${tag.name} consent mapping changed`);
}

const pageViewTag = tags.find((item) => item.name === "Meta Pixel - PageView");
assert(pageViewTag, "missing Meta PageView tag");
assert.deepEqual(new Set(pageViewTag.firingTriggerId), new Set([wordpressPageView.triggerId, javascriptPageView.triggerId]));

for (const [fixtureName, event] of Object.entries(fixtures)) {
  assert.equal(typeof event.event, "string", `${fixtureName} missing event`);
  assert.equal(typeof event.event_id, "string", `${fixtureName} missing event_id`);
  assert.equal(event.marketing_trail?.event_id, event.event_id, `${fixtureName} envelope ID mismatch`);
  assert.equal(event.marketing_trail?.event_name, event.event_name ?? (event.event === "ct_page_view" ? "page_view" : event.event));
}

assert.equal(fixtures.purchase.ecommerce.transaction_id, "order-test-001");
assert.equal(fixtures.purchase.ecommerce.currency, "EUR");
assert.equal(fixtures.purchase.ecommerce.items.length, 1);
console.log(`GTM starter contract passed: ${metaTags.length} Meta tags, ${Object.keys(fixtures).length} fixtures`);
