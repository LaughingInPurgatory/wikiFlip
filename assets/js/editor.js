/**
 * Toast UI Editor (WYSIWYG + Markdown) — saves relative Markdown.
 * Falls back to a plain Markdown textarea if the CDN editor fails to load.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("editForm");
    const host = document.getElementById("mdEditor");
    if (!form || !host) {
      return;
    }

    const cfg = window.WIKIFLIP || {};
    const saveUrl = cfg.saveUrl || "save.php";
    const uploadUrl = cfg.uploadUrl || "upload.php";
    const titleInput = document.getElementById("pageTitle");
    const slugInput = document.getElementById("pageSlug");
    const parentSelect = document.getElementById("pageParent");
    const contentField = document.getElementById("contentMarkdown");
    const saveBtn = document.getElementById("saveBtn");
    const isNew = form.querySelector('[name="is_new"]')?.value === "1";
    let toastTimer = null;
    let editor = null;
    let fallbackTa = null;

    const initialMd =
      typeof cfg.initialMarkdown === "string"
        ? cfg.initialMarkdown
        : contentField
          ? contentField.value
          : "";

    function ensureToast() {
      let el = document.getElementById("saveToast");
      if (el) return el;
      el = document.createElement("div");
      el.id = "saveToast";
      el.className = "save-toast";
      el.setAttribute("role", "status");
      el.setAttribute("aria-live", "polite");
      el.hidden = true;
      document.body.appendChild(el);
      return el;
    }

    function showStatus(message, ok) {
      const toast = ensureToast();
      if (toastTimer) clearTimeout(toastTimer);
      toast.textContent = message;
      toast.classList.remove("is-success", "is-error", "is-visible");
      toast.classList.add(ok ? "is-success" : "is-error");
      toast.hidden = false;
      void toast.offsetWidth;
      toast.classList.add("is-visible");
      toastTimer = setTimeout(function () {
        toast.classList.remove("is-visible");
        setTimeout(function () {
          toast.hidden = true;
        }, 280);
      }, ok ? 3200 : 5000);
    }

    function slugify(text) {
      return String(text || "")
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "")
        .slice(0, 80);
    }

    function currentSlug() {
      return (slugInput && slugInput.value ? slugInput.value : cfg.pageSlug || "").trim();
    }

    function mediaPreviewUrl(slug, filename) {
      const base = cfg.mediaBase || "/media.php";
      const join = base.indexOf("?") >= 0 ? "&" : "?";
      return (
        base +
        join +
        "slug=" +
        encodeURIComponent(slug) +
        "&file=" +
        encodeURIComponent(filename)
      );
    }

    function uploadFile(file) {
      const slug = currentSlug();
      if (!slug) {
        return Promise.reject("Set a URL slug before uploading media.");
      }
      const fd = new FormData();
      fd.append("file", file, file.name || "upload");
      fd.append("slug", slug);
      if (cfg.csrfToken) fd.append("csrf_token", cfg.csrfToken);
      if (titleInput) fd.append("title", titleInput.value || slug);
      if (parentSelect) fd.append("parent", parentSelect.value || "");

      return fetch(uploadUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      }).then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data.location) {
            throw data.error || data.message || "Upload failed";
          }
          return data;
        });
      });
    }

    function getMarkdown() {
      if (editor && typeof editor.getMarkdown === "function") {
        return editor.getMarkdown();
      }
      if (fallbackTa) {
        return fallbackTa.value;
      }
      return contentField ? contentField.value : "";
    }

    /** Plain Markdown fallback when Toast UI CDN is unavailable */
    function initFallback(reason) {
      console.warn("Toast UI Editor unavailable:", reason);
      host.innerHTML = "";
      const note = document.createElement("p");
      note.className = "hint";
      note.style.marginBottom = "0.5rem";
      note.textContent =
        "Visual editor could not load (" +
        reason +
        "). Using Markdown text editor — you can still write **bold**, images, etc.";
      host.appendChild(note);

      fallbackTa = document.createElement("textarea");
      fallbackTa.className = "md-fallback-textarea";
      fallbackTa.rows = 18;
      fallbackTa.value = initialMd;
      fallbackTa.setAttribute("aria-label", "Markdown content");
      host.appendChild(fallbackTa);
      showStatus("Using Markdown text editor (WYSIWYG CDN failed to load).", false);
    }

    function initToastEditor() {
      if (typeof toastui === "undefined" || !toastui.Editor) {
        initFallback("library not loaded");
        return;
      }

      try {
        editor = new toastui.Editor({
          el: host,
          height: "480px",
          initialEditType: "wysiwyg",
          previewStyle: "vertical",
          theme: "dark",
          usageStatistics: false,
          hideModeSwitch: false,
          initialValue: initialMd,
          placeholder: "Write in WYSIWYG or switch to Markdown…",
          hooks: {
            addImageBlobHook: function (blob, callback) {
              uploadFile(blob)
                .then(function (data) {
                  const url =
                    data.url || mediaPreviewUrl(currentSlug(), data.location);
                  callback(url, "image");
                })
                .catch(function (err) {
                  showStatus(
                    typeof err === "string" ? err : "Image upload failed.",
                    false
                  );
                });
            },
          },
          toolbarItems: [
            ["heading", "bold", "italic", "strike"],
            ["hr", "quote"],
            ["ul", "ol", "task", "indent", "outdent"],
            ["table", "image", "link"],
            ["code", "codeblock"],
            [
              {
                el: (function () {
                  const btn = document.createElement("button");
                  btn.type = "button";
                  btn.className = "toastui-editor-toolbar-icons pdf-toolbar-btn";
                  btn.style.backgroundImage = "none";
                  btn.style.width = "auto";
                  btn.style.margin = "0 4px";
                  btn.style.padding = "0 8px";
                  btn.style.fontSize = "12px";
                  btn.style.fontWeight = "700";
                  btn.textContent = "PDF";
                  btn.setAttribute("aria-label", "Upload PDF");
                  btn.addEventListener("click", function (ev) {
                    ev.preventDefault();
                    const input = document.createElement("input");
                    input.type = "file";
                    input.accept = "application/pdf,.pdf";
                    input.addEventListener("change", function () {
                      const file = input.files && input.files[0];
                      if (!file) return;
                      showStatus("Uploading PDF…", true);
                      uploadFile(file)
                        .then(function (data) {
                          // Store relative filename; preview uses media.php
                          const rel = data.location;
                          const url =
                            data.url ||
                            mediaPreviewUrl(currentSlug(), rel);
                          const title = (file.name || "document").replace(
                            /\.pdf$/i,
                            ""
                          );
                          // Relative src so save/relativize + view rewrite stay consistent
                          const embed =
                            '<div class="pdf-embed">' +
                            '<iframe class="pdf-frame" src="' +
                            rel +
                            '#view=FitH" title="' +
                            title.replace(/"/g, "") +
                            '"></iframe>' +
                            '<p class="pdf-embed-actions">' +
                            '<a href="' +
                            rel +
                            '" target="_blank" rel="noopener">Open PDF</a> · ' +
                            '<a href="' +
                            rel +
                            '" download>Download</a></p></div>';

                          if (editor && typeof editor.getHTML === "function") {
                            // insertText() does NOT insert HTML — it would break the iframe
                            try {
                              if (
                                typeof editor.isWysiwygMode === "function" &&
                                editor.isWysiwygMode()
                              ) {
                                editor.setHTML(
                                  editor.getHTML() + embed + "<p><br></p>"
                                );
                              } else {
                                editor.setMarkdown(
                                  editor.getMarkdown() + "\n\n" + embed + "\n\n"
                                );
                              }
                            } catch (e) {
                              editor.setMarkdown(
                                getMarkdown() + "\n\n" + embed + "\n\n"
                              );
                            }
                          } else if (fallbackTa) {
                            fallbackTa.value =
                              fallbackTa.value + "\n\n" + embed + "\n\n";
                          }
                          showStatus("PDF inserted.", true);
                        })
                        .catch(function (err) {
                          showStatus(
                            typeof err === "string" ? err : "PDF upload failed.",
                            false
                          );
                        });
                    });
                    input.click();
                  });
                  return btn;
                })(),
                name: "pdf",
                tooltip: "Upload PDF (inline)",
              },
            ],
          ],
        });
      } catch (err) {
        console.error(err);
        initFallback(err && err.message ? err.message : "init error");
      }
    }

    let slugTouched = !isNew;
    if (slugInput) {
      slugInput.addEventListener("input", function () {
        slugTouched = true;
        cfg.pageSlug = slugInput.value;
      });
    }
    if (titleInput && slugInput && isNew) {
      titleInput.addEventListener("input", function () {
        if (!slugTouched) {
          slugInput.value = slugify(titleInput.value);
          cfg.pageSlug = slugInput.value;
        }
      });
    }

    // Local vendor bundle — retry briefly if script order is odd, then fallback
    if (typeof toastui !== "undefined" && toastui.Editor) {
      initToastEditor();
    } else {
      let tries = 0;
      const t = setInterval(function () {
        tries += 1;
        if (typeof toastui !== "undefined" && toastui.Editor) {
          clearInterval(t);
          initToastEditor();
        } else if (tries >= 30) {
          clearInterval(t);
          initFallback(
            typeof toastui === "undefined"
              ? "toastui global missing — check assets/vendor/toastui/"
              : "toastui.Editor missing"
          );
        }
      }, 50);
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();

      if (!currentSlug()) {
        showStatus("URL slug is required.", false);
        return;
      }

      const md = getMarkdown();
      if (contentField) {
        contentField.value = md;
      }

      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = "Saving…";
      }

      const formData = new FormData(form);
      formData.set("content", md);

      fetch(saveUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
        .then(function (res) {
          return res.json().then(function (data) {
            return { ok: res.ok, data: data };
          });
        })
        .then(function (result) {
          const data = result.data || {};
          if (result.ok && data.success) {
            showStatus(data.message || "Saved.", true);
            if (isNew && data.edit_url) {
              setTimeout(function () {
                window.location.href = data.edit_url;
              }, 500);
            } else if (slugInput && !slugInput.readOnly) {
              slugInput.readOnly = true;
            }
          } else {
            showStatus(data.message || "Save failed.", false);
          }
        })
        .catch(function (err) {
          console.error(err);
          showStatus("Could not reach the server.", false);
        })
        .finally(function () {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = "Save changes";
          }
        });
    });
  });
})();
