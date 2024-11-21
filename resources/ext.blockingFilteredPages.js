(() => {
  const sinunReg = /\{\{סינון|תמונה\sחילופית/;
  if ($.inArray(mw.config.get("wgAction"), ["edit", "submit"]) + 1) {
    if (sinunReg.test($("#wpTextbox1").val())) {
      $(".mw-overlays-container").remove();
      document.getElementById("mw-content-text").innerHTML =
        "ערך זה מכיל תוכן הממתין לטיפול ואין באפשרותך לעורכו, לבקשת זירוז הטיפול בערך ניתן לתייג {{עורכי אספקלריה}} בדף השיחה.";
    }
  }

  //חסימת עריכת תבניות סינון ותמונה חילופית בעורך החזותי
  mw.hook("ve.activationComplete").add(() => {
    // מאזין לפתיחת דיאלוגים בעורך החזותי
    ve.init.target
      .getSurface()
      .getDialogs()
      .on("opening", (win, opening) => {
        // בודק אם זה דיאלוג עריכת תבנית
        if (win.constructor.static.name === "transclusion") {
          // ממתין לטעינת המודל של התבנית
          opening.then(function () {
            var transclusionModel = win.transclusionModel;
            if (transclusionModel?.parts?.length > 0) {
              const templateName = transclusionModel.parts[0].title.replace(
                "תבנית:",
                ""
              );
              if (
                templateName === "סינון/שורה" ||
                templateName === "סינון/פסקה" ||
                templateName === "תמונה חילופית"
              ) {
                win.close();
                mw.notify("אין באפשרותך לערוך תבנית זו!", { type: "error" });
              }
            }
          });
        }
      });
  });

  //חסימת העורך לנייד כשיש תבניות סינון
  mw.hook("mobileFrontend.editorOpened").add((editor) => {
    if (editor === "wikitext") {
      if (sinunReg.test($("#wikitext-editor").val())) {
        $(".mw-overlays-container").remove();
        mw.notify("ערך זה מכיל תוכן בעייתי ומצריך הרשאה כדי לעורכו!", {
          type: "error",
        });
      }
    }
  });
})();
