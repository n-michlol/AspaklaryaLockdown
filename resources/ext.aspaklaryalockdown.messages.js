(() => {
  const aspaklaryaLevel = mw.config.get("aspaklaryaLockdown");
  if (aspaklaryaLevel !== "none") {
    switch (aspaklaryaLevel) {
      case "read":
        mw.notify("דף זה זמין לקריאה לעורכי אספקלריה בלבד");
        break;
      case "edit":
        mw.notify("דף זה זמין לעריכה לעורכי אספקלריה בלבד");
        break;
      case "edit-semi":
        mw.notify("דף זה זמין לעריכה לעורכי אספקלריה ומעדכנים בלבד");
        break;
      case "read-semi":
        mw.notify("דף זה זמין לקריאה במהדורה הכללית בלבד");
        break;
      default:
        return;
    }
  }
})();
