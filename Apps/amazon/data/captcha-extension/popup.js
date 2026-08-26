document.addEventListener("DOMContentLoaded", async () => {
  const input = document.getElementById("apiKey");
  const toggle = document.getElementById("toggle");
  const saveBtn = document.getElementById("save");
  const testBtn = document.getElementById("test");
  const status = document.getElementById("status");

  const { apiKey } = await chrome.storage.sync.get("apiKey");
  if (apiKey) input.value = apiKey;

  toggle.addEventListener("click", () => {
    input.type = input.type === "password" ? "text" : "password";
  });

  function setStatus(text, isError) {
    status.textContent = text;
    status.classList.toggle("error", !!isError);
  }

  saveBtn.addEventListener("click", async () => {
    const val = input.value.trim();
    await chrome.storage.sync.set({ apiKey: val });
    setStatus(val ? "Saved." : "Cleared.", false);
    setTimeout(() => setStatus("", false), 1800);
  });

  testBtn.addEventListener("click", async () => {
    const val = input.value.trim();
    if (!val) {
      setStatus("Enter an API key first.", true);
      return;
    }
    setStatus("Checking…", false);
    try {
      const resp = await fetch(
        `https://2captcha.com/res.php?key=${encodeURIComponent(val)}&action=getbalance&json=1`
      );
      const data = await resp.json();
      if (data.status === 1) {
        setStatus(`Key is valid. Balance: $${data.request}`, false);
      } else {
        setStatus(`Invalid key: ${data.request}`, true);
      }
    } catch (e) {
      setStatus("Network error while checking the key.", true);
    }
  });
});
