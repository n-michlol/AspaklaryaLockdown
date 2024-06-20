(() => {
  const aspaklaryaLevel = mw.config.get("aspaklaryaLockdown") || "";

  const indicator = `<div class="mw-parser-output"><a href="/%D7%94%D7%9E%D7%9B%D7%9C%D7%95%D7%9C:%D7%94%D7%A8%D7%97%D7%91%D7%AA_%D7%90%D7%A1%D7%A4%D7%A7%D7%9C%D7%A8%D7%99%D7%94" title="דף זה נעול"><img alt="דף זה נעול" src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-30px-Full-edit-protection-shackle.svg.png" decoding="async" width="30" height="30" srcset="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-45px-Full-edit-protection-shackle.svg.png 1.5x, https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-60px-Full-edit-protection-shackle.svg.png 2x" data-file-width="512" data-file-height="512" /></a></div>`;

  if (
    aspaklaryaLevel &&
    aspaklaryaLevel !== "none" &&
    aspaklaryaLevel !== "edit-full"
  ) {
    mw.notify(mw.msg(`al-notify-${aspaklaryaLevel}`));
    if ($.inArray(mw.config.get("wgAction"), ["view", "submit"]) + 1) {
      const templateContent = `<table class="ambox toccolours" align="center" style="border: 1px solid #AFAFAF; background-color: #f9f9f9; margin-top: 5px; margin-bottom: 5px; padding: .2em; text-align: center; font-size: 100%; clear: both;">\n\n<tbody><tr>\n<td style="padding-right: 1em; text-align:right; padding-left: 1em; vertical-align: middle; width: 22px;"><a href="/%D7%94%D7%9E%D7%9B%D7%9C%D7%95%D7%9C:%D7%94%D7%A8%D7%97%D7%91%D7%AA_%D7%90%D7%A1%D7%A4%D7%A7%D7%9C%D7%A8%D7%99%D7%94" title="דף זה נעול"><img alt="דף זה נעול" src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-25px-Full-edit-protection-shackle.svg.png" decoding="async" width="25" height="25" srcset="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-38px-Full-edit-protection-shackle.svg.png 1.5x, https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/Full-edit-protection-shackle.svg/langhe-50px-Full-edit-protection-shackle.svg.png 2x" data-file-width="512" data-file-height="512" /></a>\n</td>\n<td style="text-align: right;"><span id="pl-autoconfirmed"><b><a href="/%D7%94%D7%9E%D7%9B%D7%9C%D7%95%D7%9C:%D7%94%D7%A8%D7%97%D7%91%D7%AA_%D7%90%D7%A1%D7%A4%D7%A7%D7%9C%D7%A8%D7%99%D7%94" title="המכלול:הרחבת אספקלריה">דף זה נעול</a></b></span>
                                ${mw.message("al-template-content-" + aspaklaryaLevel).parse()}.
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
