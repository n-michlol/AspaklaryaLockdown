(() => {
  const pagelockdownLevel = mw.config.get("pageLockdown") || "";

  const indicator = `<div class="mw-parser-output"><a href="/Special:MyLanguage/Extension:PageLockdown" title="דף זה נעול"><img alt="דף זה נעול" src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-40px-Full-edit-protection-shackle.svg.png" decoding="async" width="30" height="30" srcset="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-60px-Full-edit-protection-shackle.svg.png 1.5x, https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-120px-Full-edit-protection-shackle.svg.png 2x" data-file-width="512" data-file-height="512" /></a></div>`;

  if (
    pagelockdownLevel &&
    pagelockdownLevel !== "none" &&
    pagelockdownLevel !== "edit-full"
  ) {
    if (mw.user.getName()) { // Only for logged in users
      mw.notify(mw.msg(`pl-notify-${pagelockdownLevel}`));
    }
    
    if ($.inArray(mw.config.get("wgAction"), ["view", "submit"]) + 1) {
      const templateContent = `<table class="ambox toccolours" align="center" style="border: 1px solid #AFAFAF; background-color: #f9f9f9; margin-top: 5px; margin-bottom: 5px; padding: .2em; text-align: center; font-size: 100%; clear: both;">\n\n<tbody><tr>\n<td style="padding-right: 1em; text-align:right; padding-left: 1em; vertical-align: middle; width: 22px;"><a href="/Special:MyLanguage/Extension:PageLockdown" title="דף זה נעול"><img alt="דף זה נעול" src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-20px-Full-edit-protection-shackle.svg.png" decoding="async" width="25" height="25" srcset="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-40px-Full-edit-protection-shackle.svg.png 1.5x, https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-60px-Full-edit-protection-shackle.svg.png 2x" data-file-width="512" data-file-height="512" /></a>\n</td>\n<td style="text-align: right;"><span id="pl-autoconfirmed"><b><a href="/Special:MyLanguage/Extension:PageLockdown" title="Extension:PageLockdown">דף זה מוגבל</a></b></span>
                                ${mw.message("pl-template-content-" + pagelockdownLevel).parse()}.
                                \n</td></tr></tbody></table>`;
      $(".printfooter").before(
        $("<div>", {
          class: "plprotected",
          html: templateContent,
        })
      );
      $(".mw-indicators").append(
        $("<div>")
          .addClass("mw-indicator")
          .attr(
            "id",
            mw.util.escapeIdForAttribute("mw-indicator-protection-level")
          )
          .html(indicator)
          .get(0)
      );
    }
  }
})();
